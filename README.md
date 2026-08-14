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

Use `plugin/Evolution/local/settings.json.example` as the initial structure: copy it to `<directories.data>/cnf/settings.json`, configure `connection/evolution-llm` and `service-llm/evolution-llm`, and provide the configured secret. The example uses the environment variable `EVOLUTION_LLM_API_KEY`. A minimal `cnf/config.ini` snippet is provided as `plugin/Evolution/local/config.ini.example`.

## Safety boundary

Analysis is read-only. Source mutation tools reject calls unless the run context is explicit Apply mode. Apply additionally requires the configured write scope and, when `git_required=true`, a clean Git repository for the BASE3 workspace and clean version-controlled plugin repositories. BASE3 normally ignores `plugin/*` in the framework repository, so installed plugins should remain their own Git repositories. Existing unversioned plugin directories block Apply with an explicit self-check error. A newly generated plugin may be created during one approved Apply; after success it must be initialized/committed as a plugin repository before the next Apply.

When `framework_write=false`, source writes are restricted to `plugin/`.

Evolution never exposes arbitrary shell execution or arbitrary SQL. Database schema changes must be implemented as normal BASE3 migrations.
