#!/usr/bin/env python3
"""Minimal MCP client for the abilities-catalog probe server.

Usage: mcp.py <method> [params-json]
       mcp.py call <tool-name> [args-json]
       mcp.py read <uri>
       mcp.py prompt <name> [args-json]
"""
import base64
import json
import os
import sys
import urllib.request

URL = os.environ.get(
    "MCP_URL",
    "http://localhost:8888/?rest_route=/abilities-catalog/v1/mcp-probe",
)
USER = os.environ.get("MCP_USER", "admin")
PASS = os.environ.get("MCP_PASS", "")

_session = {"id": None}


def post(method, params=None, notify=False):
    body = {"jsonrpc": "2.0", "method": method}
    if not notify:
        body["id"] = 1
    if params is not None:
        body["params"] = params

    req = urllib.request.Request(URL, data=json.dumps(body).encode(), method="POST")
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json, text/event-stream")
    req.add_header("MCP-Protocol-Version", "2025-06-18")
    token = base64.b64encode(f"{USER}:{PASS}".encode()).decode()
    req.add_header("Authorization", f"Basic {token}")
    if _session["id"]:
        req.add_header("Mcp-Session-Id", _session["id"])

    try:
        with urllib.request.urlopen(req) as resp:
            sid = resp.headers.get("Mcp-Session-Id")
            if sid:
                _session["id"] = sid
            raw = resp.read().decode()
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        sid = e.headers.get("Mcp-Session-Id")
        if sid:
            _session["id"] = sid

    if not raw.strip():
        return None
    # Streamable HTTP may answer as SSE.
    if raw.lstrip().startswith("event:") or raw.lstrip().startswith("data:"):
        for line in raw.splitlines():
            if line.startswith("data:"):
                return json.loads(line[5:].strip())
        return None
    return json.loads(raw)


def connect():
    post(
        "initialize",
        {
            "protocolVersion": "2025-06-18",
            "capabilities": {},
            "clientInfo": {"name": "probe-cli", "version": "1.0"},
        },
    )
    post("notifications/initialized", notify=True)


def main():
    args = sys.argv[1:]
    if not args:
        print(__doc__)
        return 1

    connect()

    verb = args[0]
    if verb == "call":
        result = post(
            "tools/call",
            {"name": args[1], "arguments": json.loads(args[2]) if len(args) > 2 else {}},
        )
    elif verb == "read":
        result = post("resources/read", {"uri": args[1]})
    elif verb == "prompt":
        result = post(
            "prompts/get",
            {"name": args[1], "arguments": json.loads(args[2]) if len(args) > 2 else {}},
        )
    else:
        result = post(verb, json.loads(args[1]) if len(args) > 1 else {})

    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
