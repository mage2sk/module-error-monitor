# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.6.3] - 2026-10-03

### Fixed
- panth:errormonitor:status shortens long log paths to exactly the column width, so the table columns line up.
- The error group grid no longer throws a type error when it asks for search aggregations before any were set.
