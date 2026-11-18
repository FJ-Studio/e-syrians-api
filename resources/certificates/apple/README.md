# Apple signed-transaction trust anchors

These **public** PEM trust anchors were downloaded from [Apple PKI](https://www.apple.com/certificateauthority/) on 2026-11-18 and converted from DER with `openssl x509 -inform DER`. No private keys belong here. The deployment includes these files outside the public web root; the verifier never trusts a root merely because it appears in a transaction's `x5c` header.

| Certificate | Apple download | SHA-256 of DER |
| --- | --- | --- |
| Apple Root CA | https://www.apple.com/appleca/AppleIncRootCertificate.cer | `b0b1730ecbc7ff4505142c49f1295e6eda6bcaed7e2c68c5be91b5a11001f024` |
| Apple Root CA G2 | https://www.apple.com/certificateauthority/AppleRootCA-G2.cer | `c2b9b042dd57830e7d117dac55ac8ae19407d38e41d88f3215bc3a890444a050` |
| Apple Root CA G3 | https://www.apple.com/certificateauthority/AppleRootCA-G3.cer | `63343abfb89a6a03ebb57e9b3f5fa7be7c4f5c756f3017b3a8c488c3653e9179` |

Updating trust anchors requires a reviewed download from Apple's PKI site, fingerprint verification, tests, and deployment. Do not obtain them from a receipt or an arbitrary remote URL at runtime. An optional `IDENTITY_STORE_APPLE_ROOT_CERTIFICATES` override accepts comma-separated trusted absolute PEM paths; an explicitly invalid override disables iOS checkout.
