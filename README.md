# Evolution

Evolution is a BASE3/MissionBay prototype for inspecting and evolving the current BASE3 application through a controlled agent workflow.

## Runtime

Open the UI through the normal BASE3 output route:

`?name=evolutiondisplay&out=html`

The UI performs a self-check before analysis or apply is enabled.

## Configuration

Static deployment values stay in `cnf/config.ini`:

```ini
[directories]
data = "/srv/www/html/misc/evolution/local"

[evolution]
workspace = "/srv/www/html/misc/evolution"
git_required = true
framework_write = false
agent = "evolution"
```

MissionBay settings stay in the normal `ISettingsStore`. With the Website composition in this package that is `JsonSettingsStore`, stored at:

`<directories.data>/cnf/settings.json`

## OpenAI baseline

`plugin/Evolution/local/settings.json.example` contains a complete native OpenAI baseline:

- connection type: `http`
- connection driver: `http`
- base URL: `https://api.openai.com`
- service driver: `openai-chat`
- model: `gpt-4.1`
- chat model preset: `evolution-chat`
- tool profile: `evolution`
- agent: `evolution`
- custom MissionBay orchestrator profile: `evolution` (`governed`, 32 tool loops, native model decision)

Copy the settings file:

```bash
mkdir -p local/cnf local/secret
cp plugin/Evolution/local/settings.json.example local/cnf/settings.json
```

Store only the API key in the configured secret file:

```bash
printf '%s' 'YOUR_OPENAI_API_KEY' > local/secret/openai.key
chmod 600 local/secret/openai.key
```

The secret uses the existing BASE3 ConfigValue `file` mode. If the PHP process runs under another user, make the file readable by that user without making it publicly readable.

For vLLM or another OpenAI-compatible server use:

`plugin/Evolution/local/settings.openai-compatible.json.example`

and configure its endpoint root and provider model id.

Evolution uses a dedicated MissionBay orchestrator profile instead of the built-in `deliberate` profile. The built-in deliberate profile is intentionally limited to four tool loops, which is too small for repository analysis. The bundled `agent-orchestrator-profile/evolution` uses MissionBay's existing `governed` mode with `max_tool_loops = 32` and `native-model-decision`. Native model decision reuses the terminal tool-loop model response directly and avoids a second final-response model call. MissionBay does not allow native model decision together with semantic verification, so the bundled profile disables only that optional stage while keeping governed approval and the remaining pipeline intact.

## Self-check readiness

Analysis and Apply deliberately have different requirements.

Analysis requires readable source, valid settings, a resolvable LLM/agent flow, database access, StateStore and the Evolution prompt. It does not require writable source or Git-safe mutation state.

Apply additionally requires the configured write scope and, when `git_required=true`, a clean Git repository for the BASE3 workspace and clean version-controlled plugin repositories.

A read-only `local/cnf` is reported as a warning when an existing settings file can already be loaded. It does not block analysis or source Apply because Evolution does not need to rewrite its agent settings during a change.

## Safety boundary

Analysis is read-only. Source mutation tools reject calls unless the run context is explicit Apply mode. Apply additionally requires the configured write scope and, when `git_required=true`, a clean Git repository for the BASE3 workspace and clean version-controlled plugin repositories. BASE3 normally ignores `plugin/*` in the framework repository, so installed plugins should remain their own Git repositories. Existing unversioned plugin directories block Apply with an explicit self-check error. A newly generated plugin may be created during one approved Apply; after success it must be initialized/committed as a plugin repository before the next Apply.

When `framework_write=false`, source writes are restricted to `plugin/`.

Evolution never exposes arbitrary shell execution or arbitrary SQL. Database schema changes must be implemented as normal BASE3 migrations.

## Analysis snapshot semantics

Analysis never requires a Git-safe mutation state. It records a read-only fingerprint of framework/plugin source and `cnf/config.ini` together with the plan.

Apply first verifies that this source fingerprint is still identical. Only after that check, and only when Apply readiness is satisfied, Evolution creates the Git snapshot used for mutation rollback. Creating or restoring `.git` metadata between Analysis and Apply therefore does not invalidate an approved plan, while changing application source does.
