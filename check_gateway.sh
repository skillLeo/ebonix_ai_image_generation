#!/bin/bash

echo "=========================================="
echo "GATEWAY PORT CHECK"
echo "=========================================="

# Check port 8001
echo -e "\n1. Checking port 8001 (Gateway should be here):"
curl -s http://localhost:8001/health || echo "❌ Nothing on 8001"

# Check port 8000
echo -e "\n\n2. Checking port 8000 (PHP web server):"
curl -s http://localhost:8000/ | head -n 5

echo -e "\n\n=========================================="
echo "SUMMARY"
echo "=========================================="

if curl -s http://localhost:8001/health | grep -q "status"; then
    echo "✅ Gateway is running on port 8001"
else
    echo "❌ Gateway is NOT running on port 8001"
    echo "   Start it: cd ebonix_gateway && python3 run.py"
fi

if curl -s http://localhost:8000/ | grep -q "<!DOCTYPE"; then
    echo "✅ PHP web server is running on port 8000"
else
    echo "⚠️  Nothing on port 8000"
fi