//! Thin C ABI wrapper over the `ktav` crate: the entire exported surface
//! is produced by one [`ktav::declare_cabi!`] invocation.
//!
//! The macro expands, inside THIS crate, to the `extern "C"` symbols the
//! PHP consumer loads via `FFI::cdef`: `ktav_loads`, `ktav_loads_strict`,
//! `ktav_dumps`, `ktav_dumps_force_strings`, `ktav_emit_canonical`,
//! `ktav_format`, `ktav_canonical_from_source`, `ktav_free`,
//! `ktav_version` and `ktav_abi_version`. Same signatures, ownership
//! contract (`ktav_free(ptr, len)` frees exactly what a `ktav_*` call
//! returned), `0`/`1` return codes and JSON error envelopes as the
//! handwritten shim this crate carried before ktav 0.8. The wire format
//! is JSON with the `{"$i": ...}` / `{"$f": ...}` tagged wrappers for
//! Ktav's typed integers and floats; object key order survives end to
//! end.
//!
//! The expansion must live in the calling crate: symbols defined in a
//! dependency rlib are not guaranteed to survive into a downstream
//! cdylib. The invoking crate must stay edition ≤ 2021, per the macro's
//! own requirement.

ktav::declare_cabi!();
