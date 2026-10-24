/**
 * Passkey / WebAuthn Client Library
 * Handles FIDO2 / Passkey registration, authentication, Base64URL conversions, and UI interactions.
 */

const Passkey = (function () {
    'use strict';

    /**
     * Check if WebAuthn is supported in this browser
     */
    function isSupported() {
        return !!(window.PublicKeyCredential && 
                  navigator.credentials && 
                  navigator.credentials.create && 
                  navigator.credentials.get);
    }

    /**
     * Convert ArrayBuffer to Base64URL string
     */
    function bufferToBase64URL(buffer) {
        if (!buffer) return '';
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');
    }

    /**
     * Convert Base64URL or Base64 string to ArrayBuffer
     */
    function base64URLToBuffer(base64URL) {
        if (!base64URL || typeof base64URL !== 'string') {
            return new ArrayBuffer(0);
        }
        let base64 = base64URL.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) {
            base64 += '=';
        }
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * Translate DOMException error into clear user-friendly explanation
     */
    function friendlyErrorMessage(err) {
        if (!err) return 'An unknown passkey error occurred.';
        const name = err.name || '';
        const msg  = err.message || '';

        switch (name) {
            case 'NotAllowedError':
                return 'Passkey prompt was cancelled or timed out. Please try again.';
            case 'InvalidStateError':
                return 'This passkey / device has already been registered on your account.';
            case 'NotSupportedError':
                return 'Your device or browser does not support WebAuthn / Passkeys.';
            case 'SecurityError':
                return 'Passkeys require a secure HTTPS connection (or localhost during development).';
            case 'AbortError':
                return 'Passkey operation was interrupted. Please try again.';
            case 'ConstraintError':
                return 'The biometric authenticator could not satisfy the security requirements.';
            default:
                return msg || 'Authentication failed. Please use your password to sign in.';
        }
    }

    /**
     * Auto-detect device branding name
     */
    function detectDeviceName() {
        const ua = navigator.userAgent || '';
        let platform = 'My Device';

        if (/iPhone/i.test(ua)) {
            platform = 'iPhone (Face ID / Touch ID)';
        } else if (/iPad/i.test(ua)) {
            platform = 'iPad';
        } else if (/Macintosh|Mac OS X/i.test(ua)) {
            platform = 'MacBook / Mac (Touch ID)';
        } else if (/Android/i.test(ua)) {
            platform = 'Android Phone (Fingerprint / PIN)';
        } else if (/Windows NT/i.test(ua)) {
            platform = 'Windows PC (Windows Hello)';
        } else if (/Linux/i.test(ua)) {
            platform = 'Linux Security Key';
        }

        return platform;
    }

    /**
     * Safely parse JSON from fetch response, extracting clean message on HTML errors
     */
    async function parseJsonResponse(res) {
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            const clean = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            const msg = clean ? clean.substring(0, 160) : `Server HTTP ${res.status}`;
            throw new Error(`Server returned invalid response: ${msg}`);
        }
        return json;
    }

    /**
     * =========================================================================
     * REGISTRATION CEREMONY
     * =========================================================================
     */
    async function register(deviceName) {
        if (!isSupported()) {
            throw new Error('WebAuthn / Passkeys are not supported by this browser.');
        }

        const nameToSave = deviceName ? deviceName.trim() : detectDeviceName();

        // 1. Request PublicKeyCredentialCreationOptions from server
        const optResponse = await fetch('/passkey/register-options.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });

        const optJson = await parseJsonResponse(optResponse);
        if (!optResponse.ok || !optJson.success) {
            throw new Error(optJson.message || 'Failed to retrieve passkey registration options.');
        }

        const creationOptions = optJson.options;

        // 2. Decode Base64URL buffers required by navigator.credentials.create
        creationOptions.publicKey.challenge = base64URLToBuffer(creationOptions.publicKey.challenge);
        creationOptions.publicKey.user.id   = base64URLToBuffer(creationOptions.publicKey.user.id);

        if (Array.isArray(creationOptions.publicKey.excludeCredentials)) {
            creationOptions.publicKey.excludeCredentials = creationOptions.publicKey.excludeCredentials.map(cred => {
                return {
                    ...cred,
                    id: base64URLToBuffer(cred.id)
                };
            });
        }

        // 3. Trigger Browser / OS Biometric Handshake
        let credential;
        try {
            credential = await navigator.credentials.create({
                publicKey: creationOptions.publicKey
            });
        } catch (domEx) {
            throw new Error(friendlyErrorMessage(domEx));
        }

        if (!credential) {
            throw new Error('No credential was returned by the authenticator.');
        }

        // 4. Encode binary results to Base64URL for server
        const transports = credential.response.getTransports ? credential.response.getTransports() : [];
        const payload = {
            id: credential.id,
            rawId: bufferToBase64URL(credential.rawId),
            clientDataJSON: bufferToBase64URL(credential.response.clientDataJSON),
            attestationObject: bufferToBase64URL(credential.response.attestationObject),
            transports: transports,
            deviceName: nameToSave
        };

        // 5. Send credential to server for verification and storage
        const verifyResponse = await fetch('/passkey/register-verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        const verifyJson = await parseJsonResponse(verifyResponse);
        if (!verifyResponse.ok || !verifyJson.success) {
            throw new Error(verifyJson.message || 'Passkey verification failed on server.');
        }

        return verifyJson;
    }

    /**
     * =========================================================================
     * AUTHENTICATION CEREMONY
     * =========================================================================
     */
    async function login(optionalIdentifier) {
        if (!isSupported()) {
            throw new Error('WebAuthn / Passkeys are not supported by this browser.');
        }

        // 1. Fetch authentication options from server
        const requestPayload = {};
        if (optionalIdentifier && typeof optionalIdentifier === 'string') {
            requestPayload.identifier = optionalIdentifier.trim();
        }

        const optResponse = await fetch('/passkey/login-options.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(requestPayload)
        });

        const optJson = await parseJsonResponse(optResponse);
        if (!optResponse.ok || !optJson.success) {
            throw new Error(optJson.message || 'Failed to start passkey authentication.');
        }

        const requestOptions = optJson.options;

        // 2. Decode Base64URL buffers for navigator.credentials.get
        requestOptions.publicKey.challenge = base64URLToBuffer(requestOptions.publicKey.challenge);

        if (Array.isArray(requestOptions.publicKey.allowCredentials) && requestOptions.publicKey.allowCredentials.length > 0) {
            requestOptions.publicKey.allowCredentials = requestOptions.publicKey.allowCredentials.map(cred => {
                return {
                    ...cred,
                    id: base64URLToBuffer(cred.id)
                };
            });
        }

        // 3. Trigger Browser / OS Biometric Authentication Prompt
        let assertion;
        try {
            assertion = await navigator.credentials.get({
                publicKey: requestOptions.publicKey
            });
        } catch (domEx) {
            throw new Error(friendlyErrorMessage(domEx));
        }

        if (!assertion) {
            throw new Error('No assertion was returned by the authenticator.');
        }

        // 4. Encode assertion data to Base64URL for server
        const payload = {
            id: assertion.id,
            rawId: bufferToBase64URL(assertion.rawId),
            clientDataJSON: bufferToBase64URL(assertion.response.clientDataJSON),
            authenticatorData: bufferToBase64URL(assertion.response.authenticatorData),
            signature: bufferToBase64URL(assertion.response.signature),
            userHandle: assertion.response.userHandle ? bufferToBase64URL(assertion.response.userHandle) : null
        };

        // 5. Send assertion to server for cryptographic signature verification
        const verifyResponse = await fetch('/passkey/login-verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        });

        const verifyJson = await parseJsonResponse(verifyResponse);
        if (!verifyResponse.ok || !verifyJson.success) {
            throw new Error(verifyJson.message || 'Passkey authentication failed.');
        }

        return verifyJson;
    }

    /**
     * =========================================================================
     * PASSKEY MANAGEMENT API
     * =========================================================================
     */
    async function listPasskeys() {
        const res = await fetch('/passkey/list.php', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await parseJsonResponse(res);
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Failed to load passkeys.');
        }
        return json.passkeys || [];
    }

    async function renamePasskey(id, newName) {
        const res = await fetch('/passkey/rename.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id, device_name: newName })
        });
        const json = await parseJsonResponse(res);
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Failed to update passkey name.');
        }
        return json;
    }

    async function removePasskey(id, password) {
        const res = await fetch('/passkey/remove.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ id, password })
        });
        const json = await parseJsonResponse(res);
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Failed to remove passkey.');
        }
        return json;
    }

    return {
        isSupported,
        detectDeviceName,
        register,
        login,
        listPasskeys,
        renamePasskey,
        removePasskey,
        friendlyErrorMessage
    };
})();
