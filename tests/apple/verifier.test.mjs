import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync, writeFileSync } from 'node:fs';
import { X509Certificate } from 'node:crypto';
import { createVerifier, verifyTransaction } from '../../scripts/apple/verify-transaction.mjs';
import { fixtureServer } from './fixture-server.mjs';

const payload = () => ({ transactionId: '2000000123456789', bundleId: 'com.esyrians.app', environment: 'Sandbox',
    productId: 'identity_verification_credit', type: 'Consumable', quantity: 1, inAppOwnershipType: 'PURCHASED',
    appAccountToken: '1a715a78-9d2d-4a5a-9b66-4bb8741820b5', signedDate: Date.now() });
const configuration = fixture => ({ roots: fixture.roots, environment: 'sandbox', bundle: 'com.esyrians.app', appId: 6776366211 });

test('ships the reviewed Apple PKI trust anchors, not roots supplied by a transaction', () => {
    const anchors = {
        AppleIncRootCertificate: 'B0:B1:73:0E:CB:C7:FF:45:05:14:2C:49:F1:29:5E:6E:DA:6B:CA:ED:7E:2C:68:C5:BE:91:B5:A1:10:01:F0:24',
        'AppleRootCA-G2': 'C2:B9:B0:42:DD:57:83:0E:7D:11:7D:AC:55:AC:8A:E1:94:07:D3:8E:41:D8:8F:32:15:BC:3A:89:04:44:A0:50',
        'AppleRootCA-G3': '63:34:3A:BF:B8:9A:6A:03:EB:B5:7E:9B:3F:5F:A7:BE:7C:4F:5C:75:6F:30:17:B3:A8:C4:88:C3:65:3E:91:79',
    };
    for (const [name, fingerprint] of Object.entries(anchors)) {
        const certificate = new X509Certificate(readFileSync(`resources/certificates/apple/${name}.pem`));
        assert.equal(certificate.fingerprint256, fingerprint);
        assert.equal(certificate.ca, true);
    }
});

test('verifies the actual ES256 signature, certificate chain, purpose, dates and signed online OCSP responses', async () => {
    const fixture = await fixtureServer();
    try {
        const data = payload();
        assert.deepEqual(await verifyTransaction(configuration(fixture), fixture.transaction(data)), data);
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction(data, { alg: 'HS256' })));
        const jwt = fixture.transaction(data).split('.');
        jwt[1] = Buffer.from(JSON.stringify({ ...data, quantity: 99 })).toString('base64url');
        await assert.rejects(verifyTransaction(configuration(fixture), jwt.join('.')));
        jwt[1] = fixture.transaction(data).split('.')[1];
        jwt[2] = Buffer.alloc(64).toString('base64url');
        await assert.rejects(verifyTransaction(configuration(fixture), jwt.join('.')));
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction({ ...data, bundleId: 'com.other.app' })));
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction({ ...data, environment: 'Production' })));
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction({ ...data, signedDate: Date.now() + 120000 })));
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction(data, { x5c: fixture.chain.slice(0, 2) })));
        await assert.rejects(verifyTransaction({ ...configuration(fixture), roots: ['resources/certificates/apple/AppleRootCA-G3.pem'] }, fixture.transaction(data)));
        assert.throws(() => createVerifier({ ...configuration(fixture), environment: 'Xcode' }));
        writeFileSync(`${fixture.path}/status`, 'unavailable');
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction(data)));
        writeFileSync(`${fixture.path}/status`, 'good');
        const index = readFileSync(`${fixture.path}/intermediate.index`, 'utf8');
        const revoked = new Date().toISOString().replace(/[-:T]/g, '').slice(2, 14) + 'Z';
        writeFileSync(`${fixture.path}/intermediate.index`, index.replace(/^V\t([^\t]*)\t\t/, `R\t$1\t${revoked}\t`));
        await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction(data)));
    } finally { fixture.close(); }
});

test('rejects expired or wrong-purpose signing certificates, even with a trusted root and valid signature', async () => {
    for (const options of [{ withoutPurpose: true }, { leafDays: -1 }]) {
        const fixture = await fixtureServer(options);
        try { await assert.rejects(verifyTransaction(configuration(fixture), fixture.transaction(payload()))); }
        finally { fixture.close(); }
    }
});
