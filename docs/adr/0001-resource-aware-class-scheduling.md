# Resource-aware scheduling stays class-granular and local

Greenlight enforces named resource limits in one orchestrator by atomically leasing one slot per requirement for a whole class scheduling unit; unconfigured resources default to one, and the oldest blocked unit reserves its future capacity. This preserves class lifecycle and avoids a per-test worker handshake, while deliberately leaving cross-invocation coordination and exact method-granular leasing out of scope.
