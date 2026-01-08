#!/bin/bash
# Check Production Campaign LIFF Configuration

echo "🔍 Checking Production Campaign LIFF IDs"
echo "========================================"
echo ""

# Get campaigns from production API
curl -s "https://autobot.boxdesign.in.th/api/debug/check_campaigns.php" | jq '.'
