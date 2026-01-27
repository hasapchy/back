# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

To authenticate, first call the <code>POST /api/user/login</code> endpoint to get your access token. Then use the <code>access_token</code> from the response in the Authorization header as a Bearer token for all subsequent requests.<br><br><strong>Important:</strong> All API requests must include the <code>X-Company-ID</code> header with the company ID. This header is required for multi-tenant functionality.
