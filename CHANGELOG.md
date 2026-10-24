# Changelog

All Notable changes to `laravel-super-cache` will be documented in this file

## Unreleased

### Fixed
- `getKeys()`: with phpredis the SCAN loop never ran (an integer `0` cursor means "iteration finished" for phpredis, so the wrapper returned `false` and reading `$keys[0]` raised an `ErrorException`), and with predis it never terminated (the final cursor comes back as the string `"0"`, which never matched `!== 0`). The initial cursor now depends on the client (`null` for phpredis, which treats `0` and, since phpredis 6, also the string `"0"` as a finished iteration; `"0"` for predis, which rejects `null`) and the loop stops on `(string) $cursor === '0'`, the same distinction Laravel's `RedisTagSet` makes.
- `flushByTags()`: the keys of every tag shard are now read in `SSCAN` batches (`supercache.flush_batch_size`, default 1000) instead of a single `SMEMBERS`, so flushing a tag with hundreds of thousands of keys no longer dies with "Allowed memory size exhausted". Shard members are removed with one `SREM` per batch. Only single-key commands are used, so it works on Redis Cluster as well.

### Added
- `supercache.log_memory_exhaustion` (`SUPERCACHE_LOG_MEMORY_EXHAUSTION`, default `true`): a shutdown function that logs the tag being flushed when PHP dies for memory exhaustion during `flushByTags()`. It raises `memory_limit` before logging, otherwise the logger itself would die of a second out-of-memory and nothing would be written.
- `SuperCacheManager::getCurrentFlushTag()`: tag currently being invalidated by `flushByTags()`, `null` otherwise.
- Opt-in integration tests against a real Redis (standalone or cluster, phpredis or predis), see `tests/Integration/RealRedisTestCase.php`.

## 1.0.0 - 2024-10-03

- Initial release
