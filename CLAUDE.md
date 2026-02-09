# Superman Links WordPress Plugin

## DO NOT edit plugin files directly in this repo

This repo is **auto-synced from the CRM repo**. All plugin changes must be made in:

```
SupermanServicesCA/superman-links-crm → wordpress-plugin/superman-links/
```

A GitHub Action (`sync-wp-plugin.yml`) in the CRM repo automatically:
- Copies files from `wordpress-plugin/superman-links/` to this repo on push to `main`
- Creates a git tag and GitHub release when the version number changes
- Preserves `CLAUDE.md` (repo-specific, not synced from CRM)

### Workflow
1. Make plugin changes in the **CRM repo** under `wordpress-plugin/superman-links/`
2. Push to `main` on the CRM repo
3. The sync action pushes changes here and creates releases automatically
4. WordPress sites pick up updates via the auto-updater (`class-updater.php`)

### CRM repo conventions
- Uses **commitizen** with conventional commits (`feat:`, `fix:`, `refactor:`)
- Auto-generates `CHANGELOG.md` on version bumps
- Config: `cz.yaml` (semver, npm version provider)

### Packaging (manual zip for upload)
```powershell
powershell -ExecutionPolicy Bypass -File build.ps1
```
Creates `superman-links.zip` ready for WordPress upload.

### Plugin author
- Author: Superman Services
- Author URI: https://supermanservices.ca/website-design-and-development/
