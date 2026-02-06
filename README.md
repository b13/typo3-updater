# b13/typo3-updater - A Composer plugin to help you update TYPO3 core and extensions

Upgrading a TYPO3 project to a new major version involves several manual
steps:
- checking which extensions are compatible,
- finding newer versions,
- updating constraints in multiple `composer.json` files,
- and running the right Composer commands in the right order.

This plugin automates that entire workflow into two commands and a clear three-step process.

## Installation

```bash
composer require b13/typo3-updater
```

You can set it up for all your projects in a global way:

```bash
composer require global b13/typo3-updater
```

## Upgrade workflow

Upgrading from e.g. TYPO3 12 to 13 is a three-step process:

### Step 1: Prepare extensions for the target TYPO3 version

```bash
composer typo3:extensions:update ^13.4 --dry-run
```

Updates extensions to versions that are compatible with the **target** TYPO3
version, while still running on the **current** core. This is the safest first
step because it doesn't touch the core yet — only extensions are updated.

The command shows a compatibility table comparing each extension against
both the current and target TYPO3 versions, then interactively offers to bump
constraints and run `composer require`.

Use `--dry-run` to only see the table without making changes.


### Step 2: Update TYPO3 core (and remaining extensions)

```bash
composer typo3:core:update ^13.4
```

Updates all `typo3/cms-*` constraints to the target version. It also detects
any remaining extensions that are still incompatible and finds their latest
compatible versions — so everything is resolved in one `composer update -W` call.

Use `--dry-run` to preview all changes without modifying anything.

### Step 3: Update extensions to their latest versions

```bash
composer typo3:extensions:update ^13.4
```

Now that the core is at 13.4, running the same command again finds the
**latest available version** of each extension for the running TYPO3 version.
This ensures you're not stuck on the minimum compatible version but running
the newest release.

Use `--dry-run` to audit which extensions have newer versions available.

### Quick reference

```bash
# Step 1: Prepare extensions for the upgrade
composer typo3:extensions:update ^13.4

# Step 2: Upgrade TYPO3 core and remaining extensions
composer typo3:core:update ^13.4

# Step 3: Bring all extensions to their latest version
composer typo3:extensions:update ^13.4
```

## Commands

### `composer typo3:core:update <version>` - Update TYPO3 core and extensions

The main command for upgrading the TYPO3 core. It handles core packages and third-party extensions in one go.

```bash
composer typo3:core:update ^13.4 --dry-run    # Preview all changes without modifying anything
composer typo3:core:update ^13.4              # Apply changes after confirmation
```

**What it does under the hood:**

1. Reads the root `composer.json` and collects all `typo3/cms-*` packages whose constraints don't yet match the target version
2. Scans all installed extensions (packages of type `typo3-cms-extension`) and checks whether their `typo3/cms-core` constraint is compatible with the target version
3. For each incompatible extension, queries Packagist for the latest compatible version (stable first, falls back to pre-release if no stable version exists)
4. Displays up to three tables:
   - **Core packages:** `typo3/cms-*` constraint changes (e.g. `^12.4` -> `^13.4`)
   - **Extensions to upgrade:** extensions that need a version bump, including the new constraint (pre-release versions are flagged)
   - **Unresolvable extensions:** extensions with no published version compatible with the target (warning)
5. Shows the exact `composer update ... -W` command that will be executed
6. On confirmation: writes all new constraints to `composer.json` (respecting `require` vs `require-dev`) and runs `composer update` with `-W` (update with all dependencies)
7. On failure: reverts `composer.json` to its original state

**Without this plugin** you would need to manually: open `composer.json`, change every `typo3/cms-*` constraint, search Packagist or TER for each extension to find a compatible version, update those constraints too, run `composer update`, and if it fails, figure out which package is the problem and revert your changes.

---

### `composer typo3:extensions:update <version>` - Check and update extension compatibility

A versatile command for managing extensions. It serves two purposes depending on the context:

- **Before a core upgrade** (target version > installed version): shows which extensions are compatible with the target TYPO3 version and offers to update them
- **After a core upgrade** (target version = installed version): finds the latest available version of each extension for the running TYPO3 version and offers to update them

```bash
composer typo3:extensions:update ^13.4 --dry-run    # Show compatibility table only
composer typo3:extensions:update ^13.4               # Interactively update extensions
```

**What it does under the hood:**

1. Loads all installed TYPO3 extensions (from `require`, `require-dev`, and path repositories)
2. For each extension, queries Packagist for the latest version compatible with the **currently installed** TYPO3 core
3. When the target version is newer than the installed version (upgrade scenario), additionally:
   - Queries Packagist for the latest version compatible with the **target** TYPO3 version
   - Shows a compatibility column comparing current vs target
4. Displays a compatibility table with columns:
   - Current installed version
   - Latest compatible version (for the current core)
   - Compatibility status for the current TYPO3 version
   - Target TYPO3 compatibility (only in upgrade scenario)
5. With `--dry-run`: stops after the table
6. Without `--dry-run`: asks for confirmation, then interactively offers to:
   - Bump version constraints in local package `composer.json` files (e.g. path repository extensions)
   - Run `composer require` (and `composer require --dev` separately for dev dependencies) to update extensions
7. On failure: reverts all modified `composer.json` files to their original state

**Without this plugin** you would need to: manually check each extension's page on Packagist or TER, compare version constraints, figure out which version supports your target TYPO3 version, and update constraints in potentially multiple `composer.json` files across your project.

## Credits

This extension was created by Jochen Roth in 2023 for [b13 GmbH, Stuttgart](https://b13.com).

[Find more TYPO3 extensions we have developed](https://b13.com/useful-typo3-extensions-from-b13-to-you) that help us deliver value in client projects. As part of the way we work, we focus on testing and best practices to ensure long-term performance, reliability, and results in all our code.
