# Evolution

Evolution is the BASE3/MissionBay development agent for the current BASE3 application.

The application root remains the configured Evolution workspace for read access. The only writable source target is the dedicated normal BASE3 plugin:

`plugin/EvolutionWorkspace`

Evolution itself, MissionBay, Website, Foundations and BASE3 framework source are read-only reference material for the agent.

## Runtime

Open the UI through the normal BASE3 output route:

`?name=evolutiondisplay&out=html`

Analysis is read-only. Apply uses MissionBay approval before concrete mutation tools execute.

## Configuration

Static deployment values stay in `cnf/config.ini`:

```ini
[directories]
data = "/srv/www/html/misc/evolution/local"

[evolution]
workspace = "/srv/www/html/misc/evolution"
git_required = true
agent = "evolution"
```

`framework_write` is no longer used. Source mutation is always restricted to `plugin/EvolutionWorkspace`.

MissionBay settings stay in `ISettingsStore`, normally:

`<directories.data>/cnf/settings.json`

The bundled settings examples provide the `evolution` agent, `evolution-workspace` tool preset and a dedicated governed orchestrator profile with 32 tool loops and native model decision.

## EvolutionWorkspace repository

`plugin/EvolutionWorkspace` is intentionally an ordinary BASE3 plugin and the complete writable area for the agent. It should be its own Git repository, just like the other independently versioned plugins in this installation.

After installing the initial plugin files, initialize the repository with the deployment identity used for this plugin:

```bash
cd plugin/EvolutionWorkspace
git init
git add .
git commit -m "feat: initialize evolution workspace"
```

The PHP process must be able to write the EvolutionWorkspace working tree and its `.git` metadata because approved Apply operations write files and failed validation performs a local Git rollback. The self-check verifies both requirements.

When `git_required=true`, Apply requires this repository to have an initial commit and a clean working tree. Dirty state in other BASE3/plugin repositories does not block Evolution because those repositories are read-only to the agent.

## Safety boundary

Evolution may read the complete configured application root so it can inspect BASE3 contracts and existing implementation patterns. Mutation tools enforce this single source boundary:

`plugin/EvolutionWorkspace/**`

Direct `.git` file access, arbitrary shell execution and arbitrary schema-changing SQL are not exposed.

PHP writes are syntax-checked before the temporary file replaces the target file. After Apply, Evolution validates changed PHP, regenerates the BASE3 ClassMap and runs the `EvolutionWorkspace/test` PHPUnit directory when present. Failed validation restores the EvolutionWorkspace Git repository to the accepted revision.

## Analysis behavior

The agent is instructed to start with `plugin/EvolutionWorkspace`, search before broad directory listing, and inspect framework/plugins/settings/database only when the requested change depends on them. This keeps small changes small and avoids consuming repository-wide context for simple tasks.

The source fingerprint still covers relevant application source so an approved plan is invalidated when its reference implementation changes between Analysis and Apply.

## OpenAI baseline

`plugin/Evolution/local/settings.json.example` contains the native OpenAI baseline. `settings.openai-compatible.json.example` can be used for compatible providers.

Store credentials through the existing BASE3 ConfigValue mechanisms; never place secrets in agent-readable source files.
