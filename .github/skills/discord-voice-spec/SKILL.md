---
name: discord-voice-spec
description: Look up Discord Voice protocol specifications — gateway opcodes, DAVE/MLS E2EE, heartbeats, encryption modes, UDP/IP discovery, speaking payloads, buffered resume, and changelog entries. Use when implementing or debugging voice gateway behaviour, DAVE protocol transitions, or any protocol detail that should be verified against Discord's official documentation.
---

# Discord Voice Spec Skill

## Purpose

This skill provides authoritative answers about the Discord Voice protocol by querying Discord's official developer documentation. Use it whenever a task touches a protocol detail that should be verified — such as opcode numbers, payload shapes, encryption modes, DAVE MLS flow, heartbeat/seq_ack requirements, or resume behaviour.

## When to Activate

Activate this skill when the user:

- Asks what a voice gateway opcode does or what payload it carries
- Wants to verify or implement a handshake step (identify → ready → session description)
- Is working on DAVE E2EE — protocol negotiation, MLS transitions, `max_dave_protocol_version`
- Needs to know valid encryption modes (`aead_aes256_gcm_rtpsize`, `aead_xchacha20_poly1305_rtpsize`, etc.)
- Is debugging heartbeat / `seq_ack` / buffered-resume behaviour
- Wants to check UDP IP discovery or RTP packet structure
- Needs to know speaking bitmask values
- Is checking whether Discord has changed the protocol (changelog)

## Reference Paths

The key documentation pages to query:

| Topic | Path |
|---|---|
| Voice gateway protocol | `/developers/topics/voice-connections.mdx` |
| Voice REST resource | `/developers/resources/voice.mdx` |
| Opcodes & status codes | `/developers/topics/opcodes-and-status-codes.mdx` |
| Permissions | `/developers/topics/permissions.mdx` |
| Changelog | `/developers/change-log.mdx` |

## How to Use the MCP Tools

Two tools are available:

- `discord-mcp-search_documentation_discord` — keyword/semantic search across all docs. Use for broad queries ("DAVE protocol", "heartbeat seq_ack", "encryption modes").
- `discord-mcp-query_docs_filesystem_documentation_discord` — direct filesystem access. Use to read a specific page or grep for an exact term.

### Typical patterns

**Read a full page:**
```
command: cat /developers/topics/voice-connections.mdx
```

**Grep for a specific opcode or term:**
```
command: rg -i "seq_ack" /developers/topics/voice-connections.mdx
```

**Search for DAVE-related content:**
```
query: DAVE MLS protocol version negotiation
```

**Check for recent protocol changes:**
```
command: rg -i "voice" /developers/change-log.mdx -C 3
```

## Response Format

Always include:

1. **The exact opcode number or field name** from the docs
2. **The payload shape** (copy from docs if relevant)
3. **A note on the gateway version requirement** if the feature is version-gated (e.g. `seq_ack` is v8+)
4. **A link or path** to the source doc section so the developer can read more

If the docs do not cover the detail asked about, say so clearly — do not invent protocol behaviour.

## Scope

This skill is scoped to the **Discord Voice protocol only**. It does not cover the main Gateway, REST API, interactions, or other Discord subsystems. For those, use a separate reference or general web search.
