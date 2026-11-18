// Disposable synthetic PKI and signed OCSP responder. Never used by app code.
import { execFileSync } from 'node:child_process';
import { X509Certificate, sign } from 'node:crypto';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

export async function fixtureServer(options = {}) {
    const path = mkdtempSync(join(tmpdir(), 'esyrians-apple-test-'));
    const file = name => join(path, name);
    let counter = 0;
    const openssl = args => execFileSync('openssl', args, { cwd: path, stdio: ['ignore', 'pipe', 'pipe'] });
    const server = createServer(async (request, response) => {
        try {
            if (readFileSync(file('status'), 'utf8') === 'unavailable') { response.writeHead(503).end(); return; }
            const issuer = request.url === '/leaf' ? 'intermediate' : 'root';
            const prefix = String(++counter);
            const chunks = [];
            for await (const chunk of request) chunks.push(chunk);
            writeFileSync(file(`${prefix}.request`), Buffer.concat(chunks));
            openssl(['ocsp', '-index', file(`${issuer}.index`), '-CA', file(`${issuer}.pem`),
                '-rsigner', file(`${issuer}-ocsp.pem`), '-rkey', file(`${issuer}-ocsp.key`),
                '-reqin', file(`${prefix}.request`), '-respout', file(`${prefix}.response`), '-ndays', '1', '-rmd', 'sha256']);
            response.writeHead(200, { 'content-type': 'application/ocsp-response' }).end(readFileSync(file(`${prefix}.response`)));
        } catch { response.writeHead(500).end(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    function certificate(name, issuer, extensions, days = 7) {
        openssl(['req', '-new', '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:P-256', '-nodes',
            '-keyout', file(`${name}.key`), '-out', file(`${name}.csr`), '-subj', `/CN=E-Syrians disposable ${name}`]);
        writeFileSync(file(`${name}.ext`), extensions.join('\n'));
        if (days < 0) {
            writeFileSync(file('issue.index'), '');
            writeFileSync(file('issue.serial'), '01\n');
            writeFileSync(file('issue.conf'), `[ca]\ndefault_ca=issuer\n[issuer]\ndatabase=${file('issue.index')}\nserial=${file('issue.serial')}\nnew_certs_dir=${path}\ncertificate=${file(`${issuer}.pem`)}\nprivate_key=${file(`${issuer}.key`)}\ndefault_md=sha256\npolicy=policy\n[policy]\ncommonName=supplied\n`);
            const date = daysAgo => new Date(Date.now() - daysAgo * 86400000).toISOString().replace(/[-:T]/g, '').slice(0, 14) + 'Z';
            openssl(['ca', '-batch', '-config', file('issue.conf'), '-in', file(`${name}.csr`),
                '-out', file(`${name}.pem`), '-extfile', file(`${name}.ext`), '-notext',
                '-startdate', date(3), '-enddate', date(2)]);
            return;
        }
        openssl(['x509', '-req', '-in', file(`${name}.csr`), '-out', file(`${name}.pem`),
            ...(issuer ? ['-CA', file(`${issuer}.pem`), '-CAkey', file(`${issuer}.key`), '-CAcreateserial'] : ['-signkey', file(`${name}.key`)]),
            '-days', String(days), '-sha256', '-extfile', file(`${name}.ext`)]);
    }
    try {
    certificate('root', null, ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign']);
    certificate('intermediate', 'root', ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign',
        '1.2.840.113635.100.6.2.1=DER:05:00', `authorityInfoAccess=OCSP;URI:${origin}/intermediate`]);
    certificate('leaf', 'intermediate', ['basicConstraints=critical,CA:FALSE', 'keyUsage=critical,digitalSignature',
        ...(options.withoutPurpose ? [] : ['1.2.840.113635.100.6.11.1=DER:05:00']),
        `authorityInfoAccess=OCSP;URI:${origin}/leaf`], options.leafDays ?? 2);
    for (const issuer of ['root', 'intermediate']) {
        certificate(`${issuer}-ocsp`, issuer, ['basicConstraints=critical,CA:FALSE',
            'keyUsage=critical,digitalSignature', 'extendedKeyUsage=OCSPSigning']);
        const target = new X509Certificate(readFileSync(file(`${issuer === 'root' ? 'intermediate' : 'leaf'}.pem`)));
        const expiry = new Date(target.validTo).toISOString().replace(/[-:T]/g, '').slice(2, 14) + 'Z';
        writeFileSync(file(`${issuer}.index`), `V\t${expiry}\t\t${target.serialNumber}\tunknown\t/CN=fixture\n`);
    }
    writeFileSync(file('status'), 'good');
    const chain = ['leaf', 'intermediate', 'root'].map(name => new X509Certificate(readFileSync(file(`${name}.pem`))).raw.toString('base64'));
    return {
        path, roots: [file('root.pem')], chain, key: file('leaf.key'), origin,
        transaction(payload, header = {}) {
            const encoded = [JSON.stringify({ alg: 'ES256', x5c: chain, ...header }), JSON.stringify(payload)]
                .map(value => Buffer.from(value).toString('base64url')).join('.');
            return `${encoded}.${sign('sha256', Buffer.from(encoded), { key: readFileSync(file('leaf.key')), dsaEncoding: 'ieee-p1363' }).toString('base64url')}`;
        },
        close() { server.closeAllConnections(); server.close(); rmSync(path, { recursive: true, force: true }); },
    };
    } catch (error) {
        server.closeAllConnections(); server.close(); rmSync(path, { recursive: true, force: true });
        throw error;
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const fixture = await fixtureServer();
    process.stdout.write(JSON.stringify({ path: fixture.path, roots: fixture.roots, chain: fixture.chain, key: fixture.key }) + '\n');
    process.on('SIGTERM', () => { fixture.close(); process.exit(0); });
}
