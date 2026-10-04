# Changelog

All notable changes to Rugby State Machine are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html) (see *Versioning* in the
[README](README.md#versioning)).

## [Unreleased]

## [1.0.0] - 2026-10-04

First public release.

### Added

- State-driven live tagging of rugby union matches: the game logic is a declarative graph
  (`src/States.php`) aligned with the World Rugby *Laws of the Game* in force from 1 July 2026,
  with the relevant law cited for each rule.
- Live mode and split-screen mode (video + tagger) with YouTube, Vimeo or a local video file
  streamed from disk; the match clock follows the video from the first kick-off.
- Undo of the last action, cards, substitutions, advantage, left/right team swap.
- Statistics: possession, scores, kicks at goal with kick map, mauls, turnovers, knock-ons,
  reclaimed kick-offs, time scrubber.
- JSON export and import of one or more matches.
- Italian and English interface, detected from the browser and switchable.
- Sign-in with three roles (administrator, tagger, viewer), user management, bcrypt password
  hashing, sign-in throttling, session invalidation on password change or deactivation,
  emergency password reset from the command line (`tools/reset-password.php`).
- Protections for Apache (`.htaccess`) and for PHP's built-in server (`router.php`).
- README in English and Italian with Mermaid diagrams of the state graph.

[Unreleased]: https://github.com/fproperzi/rugby_state_machine/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/fproperzi/rugby_state_machine/releases/tag/v1.0.0
