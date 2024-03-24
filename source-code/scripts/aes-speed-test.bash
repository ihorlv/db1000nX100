#!/usr/bin/env bash

openssl speed -elapsed  -evp aes-128-gcm  -bytes 1024
OPENSSL_ia32cap="~0x200000200000000" openssl speed -elapsed  -evp aes-128-gcm  -bytes 1024  # Test with disabled Intel/Amd AES hardware acceleration

openssl speed -elapsed  -evp aes-256-gcm  -bytes 1024
openssl speed -elapsed  -evp aes-128-cbc  -bytes 1024
openssl speed -elapsed  -evp aes-256-cbc  -bytes 1024
openssl speed -elapsed  -evp ChaCha20-Poly1305  -bytes 1024
