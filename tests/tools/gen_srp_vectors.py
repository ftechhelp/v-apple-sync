#!/usr/bin/env python3
"""Generate SRP test vectors for tests/Unit/ICloud/Auth/SrpTest.php.

Uses pysrp (`pip install srp`) configured the way pyicloud talks to Apple:
RFC 5054 padding, SHA-256, 2048-bit group, no username in x. Each vector is a
full client/server round trip, so pysrp's own verifier has accepted M1 and the
client has accepted the server's H_AMK before the vector is written.

Usage: python3 tests/tools/gen_srp_vectors.py > tests/fixtures/srp_vectors.json
"""
import base64
import hashlib
import json
import os

import srp._pysrp as pysrp

pysrp.rfc5054_enable()
pysrp.no_username_in_x()


def derive(password: str, salt: bytes, iterations: int, protocol: str) -> bytes:
    digest = hashlib.sha256(password.encode("utf-8")).digest()
    if protocol == "s2k_fo":
        digest = digest.hex().encode()
    return hashlib.pbkdf2_hmac("sha256", digest, salt, iterations, 32)


def b64(data: bytes) -> str:
    return base64.b64encode(data).decode()


vectors = []
cases = [
    ("s2k", "alice@example.com", "correct horse battery staple", 20000),
    ("s2k_fo", "alice@example.com", "correct horse battery staple", 20000),
    ("s2k", "bob@icloud.com", "pässwörd-ünïcode-🍎", 1000),
    ("s2k_fo", "bob@icloud.com", "pässwörd-ünïcode-🍎", 1000),
    ("s2k", "carol@me.com", "x", 1),
    ("s2k_fo", "carol@me.com", "x", 1),
]
for protocol, username, password, iterations in cases:
    salt = os.urandom(16)
    key = derive(password, salt, iterations, protocol)
    a = os.urandom(256)
    b = os.urandom(256)

    # Server side: verifier for (salt, key), challenge B.
    x = pysrp.gen_x(hashlib.sha256, salt, username.encode(), key)
    n, g = pysrp.get_ng(pysrp.NG_2048, None, None)
    vkey = pysrp.long_to_bytes(pow(g, x, n))

    usr = pysrp.User(username.encode(), key, hash_alg=pysrp.SHA256, ng_type=pysrp.NG_2048, bytes_a=a)
    _, A = usr.start_authentication()
    svr = pysrp.Verifier(username.encode(), salt, vkey, A, hash_alg=pysrp.SHA256, ng_type=pysrp.NG_2048, bytes_b=b)
    s, B = svr.get_challenge()
    M = usr.process_challenge(s, B)
    assert M is not None, "client rejected challenge"
    HAMK = svr.verify_session(M)
    assert HAMK is not None, "server rejected M1"
    usr.verify_session(HAMK)
    assert usr.authenticated(), "client rejected server proof"

    vectors.append({
        "protocol": protocol,
        "username": username,
        "password": password,
        "iterations": iterations,
        "salt": b64(salt),
        "a": b64(a),
        "B": b64(B),
        "derivedKey": b64(key),
        "A": b64(A),
        "M1": b64(M),
        "M2": b64(HAMK),
    })

print(json.dumps(vectors, indent=2, ensure_ascii=False))
