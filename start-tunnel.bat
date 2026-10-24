@echo off
title Cloudflare Public Tunnel - Port 80
echo ===================================================
echo   Starting Cloudflare Public HTTPS Tunnel for XAMPP
echo ===================================================
echo.
echo Forwarding traffic to http://127.0.0.1:80 ...
echo (Keep this window open to maintain the connection)
echo.
cloudflared tunnel --url http://127.0.0.1:80
pause
