import { X509Certificate } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { Environment, SignedDataVerifier, VerificationException, VerificationStatus } from '@apple/app-store-server-library';

export function createVerifier(configuration) {
    if (Number(process.versions.node.split('.')[0]) < 22
        || !['production', 'sandbox'].includes(configuration.environment)
        || typeof configuration.bundle !== 'string' || !configuration.bundle
        || !Number.isSafeInteger(configuration.appId) || configuration.appId <= 0
        || !Array.isArray(configuration.roots) || !configuration.roots.length) {
        throw new Error('Invalid verifier configuration');
    }
    const roots = configuration.roots.map(path => {
        const certificate = new X509Certificate(readFileSync(path));
        if (!certificate.ca) throw new Error('Not a trust anchor');
        return certificate.raw;
    });
    // Online revocation checks are mandatory. Xcode/LocalTesting (which skip
    // signature verification in Apple's library) are deliberately unavailable.
    return new SignedDataVerifier(roots, true,
        configuration.environment === 'production' ? Environment.PRODUCTION : Environment.SANDBOX,
        configuration.bundle, configuration.appId);
}

export async function verifyTransaction(configuration, transaction) {
    if (typeof transaction !== 'string' || transaction.length > 65536
        || !/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(transaction)) {
        throw new VerificationException(VerificationStatus.VERIFICATION_FAILURE);
    }
    let header;
    try { header = JSON.parse(Buffer.from(transaction.split('.')[0], 'base64url').toString('utf8')); }
    catch { throw new VerificationException(VerificationStatus.VERIFICATION_FAILURE); }
    if (header.alg !== 'ES256' || header.crit !== undefined) {
        throw new VerificationException(VerificationStatus.VERIFICATION_FAILURE);
    }
    const data = await createVerifier(configuration).verifyAndDecodeTransaction(transaction);
    if (!Number.isSafeInteger(data.signedDate) || data.signedDate <= 0 || data.signedDate > Date.now() + 60000) {
        throw new VerificationException(VerificationStatus.VERIFICATION_FAILURE);
    }
    // Return only the fields Laravel needs, never the complete purchase payload.
    return Object.fromEntries(['transactionId', 'bundleId', 'environment', 'productId', 'type', 'quantity',
        'inAppOwnershipType', 'appAccountToken', 'revocationDate', 'signedDate'].filter(key => data[key] !== undefined)
        .map(key => [key, data[key]]));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const chunks = [];
        let length = 0;
        for await (const chunk of process.stdin) {
            length += chunk.length;
            if (length > 131072) throw new Error('Input too large');
            chunks.push(chunk);
        }
        const input = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        const result = input.check === true
            ? (createVerifier(input), { ready: true })
            : await verifyTransaction(input, input.transaction);
        process.stdout.write(JSON.stringify(result));
    } catch (error) {
        // No exception detail, JWS, certificate contents, or account identifiers
        // may enter process logs. Exit codes distinguish reject from retry.
        process.stderr.write('Apple transaction verification failed\n');
        process.exitCode = error instanceof VerificationException
            && error.status !== VerificationStatus.RETRYABLE_VERIFICATION_FAILURE ? 10 : 20;
    }
}
