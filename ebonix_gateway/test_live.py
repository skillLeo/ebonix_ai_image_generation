"""
Test gateway while it's running
"""
import requests
import json

GATEWAY_URL = "http://localhost:8000"
TOKEN = "ebonix_secret_12345"

def test_health():
    print("1️⃣  Testing /health...")
    try:
        r = requests.get(f"{GATEWAY_URL}/health", timeout=5)
        print(f"   Status: {r.status_code}")
        print(f"   Response: {r.json()}")
        return r.status_code == 200
    except Exception as e:
        print(f"   ❌ Error: {e}")
        return False

def test_generate():
    print("\n2️⃣  Testing /generate...")
    
    payload = {
        "type": "image",
        "prompt": "beautiful girl",
        "model": "banana",
        "size": "1024x1024",
        "representation_rules": {
            "skin_tone": "diverse",
            "hair_texture": "diverse",
            "prevent_whitewashing": True
        }
    }
    
    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {TOKEN}"
    }
    
    print(f"   Sending request...")
    print(f"   Prompt: {payload['prompt']}")
    
    try:
        r = requests.post(
            f"{GATEWAY_URL}/generate",
            json=payload,
            headers=headers,
            timeout=180
        )
        
        print(f"   Status: {r.status_code}")
        
        if r.status_code == 200:
            data = r.json()
            if data.get('success'):
                print(f"   ✅ SUCCESS!")
                print(f"   Model: {data.get('model_used')}")
                print(f"   Enhanced prompt: {data.get('enhanced_prompt')[:100]}...")
                print(f"   Image size: {len(data.get('image_url', ''))} chars")
                return True
            else:
                print(f"   ❌ Failed: {data.get('error')}")
                return False
        else:
            print(f"   ❌ HTTP {r.status_code}: {r.text[:200]}")
            return False
            
    except Exception as e:
        print(f"   ❌ Error: {e}")
        return False

if __name__ == "__main__":
    print("=" * 60)
    print("GATEWAY LIVE TEST")
    print("=" * 60)
    
    if not test_health():
        print("\n❌ Health check failed! Gateway not running?")
        exit(1)
    
    print("\n✅ Health check passed!")
    print("\n⏳ Testing image generation (may take 60s)...")
    
    if test_generate():
        print("\n" + "=" * 60)
        print("🎉 ALL TESTS PASSED!")
        print("=" * 60)
    else:
        print("\n" + "=" * 60)
        print("❌ Generation test failed")
        print("=" * 60)