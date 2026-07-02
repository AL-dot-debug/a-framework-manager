# The Framework

An open knowledge base of tactics, techniques, and sub-techniques, published as
STIX 2.1 and maintained through an open proposal-and-review process. The
knowledge base is organised into one or more **sub-frameworks**, each with a
three-level hierarchy (tactic → technique → sub-technique). The exact names,
prefixes and terminology are defined by the framework's profile.

## Getting started

### Which file should I use?

Each [release](../../releases) includes several formats. Pick the one that suits
your workflow (`<slug>` is the framework's artefact slug):

| If you want to… | Download | Format |
|---|---|---|
| Import into a threat-intelligence platform (MISP, OpenCTI, ATT&CK Navigator) | `<slug>-v{version}.stix.json` | STIX 2.1 bundle |
| Parse programmatically in a script or dashboard | `<slug>-v{version}.json` | Flat JSON with tactics and techniques arrays |
| Open in a spreadsheet or feed into a report | `<slug>-v{version}.csv.zip` | CSV archive (tactics, techniques, sub-techniques) |

Translated variants are available where translations exist (e.g.
`<slug>-v{version}.fr.stix.json` for French). Untranslated items fall back to the
source language.

### Quick examples

**Python — load the STIX bundle and list every attack-pattern:**

```python
import json

with open("<slug>-v{version}.stix.json") as f:
    bundle = json.load(f)

for obj in bundle["objects"]:
    if obj["type"] == "attack-pattern":
        framework_id = obj["external_references"][0]["external_id"]
        indent = "  " if obj.get("x_mitre_is_subtechnique") else ""
        print(f"{indent}{framework_id}: {obj['name']}")
```

**Python — filter objects by sub-framework** (the membership property name comes
from the profile, e.g. `x_<prefix>_framework`, and its value is the sub-framework
slug):

```python
MEMBERSHIP_PROP = "x_<prefix>_framework"   # see the bundle's extension-definition
members = [o for o in bundle["objects"] if o.get(MEMBERSHIP_PROP) == "<slug>"]
```

**Python — build a parent/child map from `subtechnique-of` relationships:**

```python
parent_of = {}
for obj in bundle["objects"]:
    if obj["type"] == "relationship" and obj["relationship_type"] == "subtechnique-of":
        parent_of[obj["source_ref"]] = obj["target_ref"]
```

## How the framework is organised

Every sub-framework follows the same three-level hierarchy (only the display
names differ per sub-framework):

- **Tactics** (`<PREFIX>###`) — the top-level groupings.
- **Techniques** (`<PREFIX>###.###`) — specific methods within a tactic.
- **Sub-techniques** (`<PREFIX>###.###.###`) — finer-grained variants of a technique.

Sub-techniques are linked to their parent technique via `subtechnique-of`
relationships. Objects can also be linked (including across sub-frameworks) using
`related-to` relationships.

## STIX 2.1 compatibility

The bundle follows ATT&CK conventions so existing tooling works out of the box:

- Tactics are `x-mitre-tactic` objects; techniques and sub-techniques are
  `attack-pattern` objects.
- Sub-techniques carry `x_mitre_is_subtechnique: true` and a `subtechnique-of`
  relationship to their parent.
- Sub-framework membership is indicated by `kill_chain_name` (on attack-patterns)
  and a custom membership property (on tactics), both defined by the profile.
- Every object carries a framework ID in its `external_references` with a `url`
  pointing to its documentation page.
- The bundle includes an `identity`, a `marking-definition` (CC-BY-SA-4.0), an
  `extension-definition` documenting the custom properties, and one `grouping`
  per sub-framework listing its member objects.

## Repository structure

Source YAML files are organised by sub-framework slug:

```
<slug>/
  objects/
    tactics/            <PREFIX>001.yaml, ...
    techniques/         <PREFIX>001.001.yaml, ...
    subtechniques/      <PREFIX>001.001.001.yaml, ...
  documentation/        Auto-generated Markdown pages
  translations/         Per-language overrides (id, framework, name, description)
schema/                 JSON schemas used by CI validation
```

## Versioning

- **Major** — structural or conceptual overhauls.
- **Minor** — new objects or significant revisions.
- **Patch** — corrections, clarifications, editorial changes.

See [CHANGELOG.md](CHANGELOG.md) for version history.

## Licence

[CC-BY-SA-4.0](LICENSE.md)
