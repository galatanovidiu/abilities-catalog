#!/usr/bin/env python3
"""Re-run every PR-260 probe scenario and report pass/fail against the expectation."""
import json
import subprocess
import sys

MCP = ["python3", "mcp.py"]


def call(*args):
    out = subprocess.run(MCP + list(args), capture_output=True, text=True).stdout
    return json.loads(out)


def find(items, key, val):
    for i in items:
        if i.get(key) == val:
            return i
    return None


results = []


def check(num, desc, ok, detail):
    results.append((num, desc, ok, detail))


# --- resources ---
rl = call("resources/list")["result"]["resources"]
tl = call("tools/list")["result"]["tools"]

# 1: _meta object survives on resource contents
c = call("read", "ui://probe/app")["result"]["contents"][0]
check(1, "_meta object on resource contents",
      isinstance(c.get("_meta"), dict) and bool(c["_meta"]),
      f"_meta={c.get('_meta')}")

# 2: list-shaped _meta omitted
c = call("read", "ui://probe/bad-meta")["result"]["contents"][0]
check(2, "list-shaped _meta omitted (warning logged)",
      "_meta" not in c,
      f"keys={sorted(c.keys())}")

# 3: blob-only items -> one BlobResourceContents each, resource URI inherited
r = call("read", "probe://blob")["result"]["contents"]
check(3, "blob-only items become BlobResourceContents",
      len(r) == 2 and all("blob" in i and i.get("uri") == "probe://blob" for i in r),
      f"{len(r)} items, uris={[i.get('uri') for i in r]}, mime={[i.get('mimeType') for i in r]}")

# 3b: descriptor mimeType (added this session)
d = find(rl, "uri", "probe://blob")
check("3b", "blob resource descriptor declares mimeType",
      d and d.get("mimeType") == "image/png",
      f"mimeType={d.get('mimeType') if d else 'MISSING'}")

# 4a / 4b: descriptor _meta on tools/list
t = find(tl, "name", "probe-meta-tool-meta-object")
check("4a", "descriptor _meta object reaches tools/list",
      isinstance(t.get("_meta"), dict) and bool(t["_meta"]), f"_meta={t.get('_meta')}")
t = find(tl, "name", "probe-meta-tool-meta-list")
check("4b", "descriptor _meta list omitted",
      "_meta" not in t, f"keys={sorted(t.keys())}")

# 5 / 6: embedded resource nested + flat
for num, tool in (("5", "probe-meta-tool-embedded-nested"), ("6", "probe-meta-tool-embedded-flat")):
    b = call("call", tool)["result"]["content"][0]
    inner = b.get("resource", {})
    check(num, f"embedded resource ({'nested' if num == '5' else 'flat'} form)",
          b.get("type") == "resource" and isinstance(inner.get("_meta"), dict),
          f"block keys={sorted(b.keys())}, contents _meta={inner.get('_meta')}, "
          f"block _meta={b.get('_meta')}, annotations={b.get('annotations')}")

# 7: image with sibling annotations and _meta
b = call("call", "probe-meta-tool-image")["result"]["content"][0]
check(7, "image result carries sibling annotations and _meta",
      b.get("type") == "image" and b.get("annotations") and b.get("_meta"),
      f"mimeType={b.get('mimeType')}, annotations={b.get('annotations')}, _meta={b.get('_meta')}")

# 8: no type -> stays tool data
res = call("call", "probe-meta-tool-no-type")["result"]
check(8, "result with no `type` stays in structuredContent",
      "structuredContent" in res and all(x.get("type") != "image" for x in res.get("content", [])),
      f"content types={[x.get('type') for x in res.get('content', [])]}, "
      f"structuredContent={'yes' if 'structuredContent' in res else 'no'}")

# 9a: coerced descriptor annotation
t = find(tl, "name", "probe-meta-tool-annotations-coerced")
ann = (t or {}).get("annotations", {})
check("9a", "string '1' coerced to readOnlyHint: true",
      ann.get("readOnlyHint") is True, f"annotations={ann}")

# 9b: coerced result annotation
b = call("call", "probe-meta-tool-result-priority-string")["result"]["content"][0]
pri = (b.get("annotations") or {}).get("priority")
check("9b", "string '0.5' coerced to float priority",
      isinstance(pri, float) and abs(pri - 0.5) < 1e-9, f"priority={pri!r} ({type(pri).__name__})")

# 10: prompt message the DTOs refuse degrades to text
p = call("prompt", "probe-meta-prompt-invalid-embedded")["result"]["messages"]
types = [m.get("content", {}).get("type") for m in p]
check(10, "unrepresentable prompt block degrades to text",
      all(t == "text" for t in types), f"message content types={types}")

# --- report ---
width = max(len(d) for _, d, _, _ in results)
allok = True
for num, desc, ok, detail in results:
    allok &= bool(ok)
    print(f"{'PASS' if ok else 'FAIL'}  {str(num):>3}  {desc:<{width}}  {detail}")
print("\nALL PASS" if allok else "\nSOME FAILED")
sys.exit(0 if allok else 1)
