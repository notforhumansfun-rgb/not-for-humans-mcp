import json

payload = {
    "action": "prepare",
    "tokenId": 1,
    "bypassWakeGate": True,
    "forceHolderVerified": True
}

print("[+] Payload de prueba generado para el bypass del Wake Holder Gate:")
print(json.dumps(payload, indent=2))