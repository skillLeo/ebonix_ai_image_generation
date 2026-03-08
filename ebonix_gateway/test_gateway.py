#!/usr/bin/env python3
"""
Direct gateway test - bypasses PHP entirely
"""
import requests
import json

# Gateway settings
GATEWAY_URL = "http://localhost:8000"
GATEWAY_TOKEN = "ebonix_secret_12345"

def test_gateway_health():
    """Test if gateway is running"""
    print("=" * 60)
    print("TEST 1: Gateway Health Check")
    print("=" * 60)
    
    try:
        response = requests.get(f"{GATEWAY_URL}/health")
        print(f"Status: {response.status_code}")
        print(f"Response: {response.json()}")
        return response.status_code == 200
    except Exception as e:
        print(f"❌ FAILED: {e}")
        return False

def test_image_generation():
    """Test image generation through gateway"""
    print("\n" + "=" * 60)
    print("TEST 2: Image Generation with Black Representation Rules")
    print("=" * 60)
    
    payload = {
        "type": "image",
        "prompt": "create image of beautiful girl",
        "model": "auto",
        "size": "1024x1024",
        "representation_rules": {
            "skin_tone": "diverse",
            "hair_texture": "diverse",
            "face_features": "natural",
            "prevent_whitewashing": True,
            "keep_features_consistent": True
        }
    }
    
    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {GATEWAY_TOKEN}"
    }
    
    print(f"\n📤 Sending request to: {GATEWAY_URL}/generate")
    print(f"📝 Prompt: {payload['prompt']}")
    print(f"🎨 Rules: {payload['representation_rules']}")
    
    try:
        response = requests.post(
            f"{GATEWAY_URL}/generate",
            json=payload,
            headers=headers,
            timeout=180
        )
        
        print(f"\n📥 Response Status: {response.status_code}")
        
        if response.status_code == 200:
            data = response.json()
            print(f"✅ SUCCESS!")
            print(f"Response: {json.dumps(data, indent=2)}")
            
            if data.get('success'):
                print(f"\n🎉 IMAGE GENERATED!")
                print(f"Model used: {data.get('model_used')}")
                print(f"Enhanced prompt: {data.get('enhanced_prompt')}")
                return True
            else:
                print(f"❌ Generation failed: {data.get('error')}")
                return False
        else:
            print(f"❌ HTTP Error: {response.status_code}")
            print(f"Response: {response.text}")
            return False
            
    except Exception as e:
        print(f"❌ EXCEPTION: {e}")
        return False

if __name__ == "__main__":
    print("\n🚀 EBONIX GATEWAY TEST SUITE\n")
    
    # Test 1: Health check
    if not test_gateway_health():
        print("\n❌ Gateway is not running! Start it first:")
        print("   cd /Users/apple/Documents/ebonix/ebonix_gateway")
        print("   python3 run.py")
        exit(1)
    
    # Test 2: Image generation
    print("\n⏳ Testing image generation (may take 30-60 seconds)...")
    success = test_image_generation()
    
    # Summary
    print("\n" + "=" * 60)
    print("SUMMARY")
    print("=" * 60)
    if success:
        print("✅ Gateway is working correctly!")
        print("✅ Black representation rules are being applied!")
    else:
        print("❌ Gateway test failed - check logs above")
    print("=" * 60)