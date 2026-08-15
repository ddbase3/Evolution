# Evolution

Evolution is the BASE3/MissionBay development agent for the current BASE3 application.

The application root remains the configured Evolution workspace for read access. The only writable source target is the dedicated normal BASE3 plugin:

`plugin/EvolutionWorkspace`

Evolution itself, MissionBay, Website, Foundations and BASE3 framework source are read-only reference material for the agent.

## Runtime

Open the UI through the normal BASE3 output route:

`?name=evolutiondisplay&out=html`

Analysis and Apply use one persistent MissionBay run. Evolution activates its run-local `evolutionplanningmodule`, which mounts a planning guard into MissionBay before action policy. The agent reads the application and must either submit a complete `evolution_apply_plan` tool call or report an exact blocker through the read-only `evolution_report_blocker` tool. Evolution validates that pending plan before it is shown to the user. Invalid proposals such as no-op writes are rejected back into the same MissionBay run as a concrete tool observation so the agent can correct the plan without starting a second analysis. Once a valid plan is pending, the UI renders its exact stored operations. `Apply approved plan` resumes that same MissionBay run and authorizes exactly that operation set; no second model decision is used to translate the plan into writes.

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

The bundled settings examples provide the `evolution` agent, `evolution-workspace` tool preset and a dedicated governed orchestrator profile with 32 tool loops, `ai-guarded-model-decision`, MissionBay context compaction, semantic verification and deliberate planning. Deliberate planning adds no extra planning model call; it instructs the existing orchestrator to use focused evidence calls and stop when the task is supported.

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

Evolution may read the complete configured application root so it can inspect BASE3 contracts and existing implementation patterns. The single approval-bound apply-plan tool enforces this source boundary:

`plugin/EvolutionWorkspace/**`

Direct `.git` file access, arbitrary shell execution and arbitrary schema-changing SQL are not exposed.

PHP writes are syntax-checked before the temporary file replaces the target file. After Apply, Evolution validates changed PHP, forces `IClassMap::generate(true)`, verifies both `DIR_TMP/classmap.php` and `DIR_TMP/ctorcache.php`, and then runs the `EvolutionWorkspace/test` PHPUnit directory when present. Failed validation restores the EvolutionWorkspace Git repository to the accepted revision and regenerates the discovery artifacts again.

## Analysis behavior

The agent is instructed to start with `plugin/EvolutionWorkspace`, search before broad directory listing, and inspect framework/plugins/settings/database only when the requested change depends on them. `evolution_read_file` returns bounded line ranges (160 lines by default, up to 1200) with line metadata and `has_more`, so a search hit can be inspected without injecting a complete large source file into every later model decision. Independent known reads should be batched in one tool-call turn.

MissionBay `context-compaction` remains the only AI summarization mechanism in this path. In the governed profile it runs between tool execution and tool observation and summarizes individual successful tool results above MissionBay's configured threshold before they become model messages. Evolution does not maintain a second summarized context. Focused source reads are still important because compaction itself uses the active model and therefore also consumes provider tokens.

The source fingerprint still covers relevant application source so an approved plan is invalidated when its reference implementation changes between Analysis and Apply. The Evolution planning guard is a run-local MissionBay module stage: if model decision attempts to complete an implementable planning run without `evolution_apply_plan`, it returns the same run to model decision with a concrete continuation instruction. It does not start a second agent run. Exact blockers use `evolution_report_blocker` and therefore terminate through an explicit structured outcome rather than free text. Framework-dependent plans must inspect the exact current BASE3 contract before submitting `evolution_apply_plan`; discoverable ClassMap components are not container-registered merely for discovery. Before user approval, Evolution validates the pending operation set against the current workspace, including no-op writes, duplicate target paths and the writable boundary. A rejected proposal is returned to the same MissionBay run for correction. The accepted plan contains the complete ordered mutation set and complete content for every write, so Apply is deterministic and does not ask the model to decide again whether or how to write the approved files.

## OpenAI baseline

`plugin/Evolution/local/settings.json.example` contains the native OpenAI baseline. `settings.openai-compatible.json.example` can be used for compatible providers.

Store credentials through the existing BASE3 ConfigValue mechanisms; never place secrets in agent-readable source files.
