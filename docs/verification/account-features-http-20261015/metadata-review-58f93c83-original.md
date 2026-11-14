DECISION: REVISE COMPONENT — ONE RELATED RAW-MAP GUARD REMAINS

Reviewed exact clean `58f93c83bff044e1ce2db23bf1f6ae579ef0807b`. No P0/P1 is established. The specified deferred/contextual/alias-map repairs and early CustomerAccess/FeatureAccess lock/frame ordering correctly close their named paths. The corrected committed control now uses the required envelope and reaches the intended old policy path; its earlier empty-array version remains explicitly ineffective.

One continuation of the resolver P2 remains. The supported standard `offsetSet('env', literal)` binding without an env instance still delegates through `IdentityPolicy::enabled` -> `Application::environment` -> `Container::resolve`. That branch appends `$this->with[]` and writes `$this->resolved[$abstract]` (installed Container lines 929 and 964). Neither raw property is admitted as an actual array. ArrayAccess replacements can therefore execute setter callbacks after the new guard passes.

Require raw `with` and `resolved` arrays before that delegation. Add individual setter-counting controls using the actual standard literal env descriptor, with no env instance: old source reaches setters, fixed source refuses with zero setters and no frame effects. Preserve a supported literal-descriptor positive. This is the same reached resolver-metadata group, requiring no new policy/source/deadline or frozen-family change.

The active eleven-case metadata run is not claimed complete here. Parent-reported prior fixed four-case success and original red controls remain their original receipts; the reviewer inspected source/test mechanisms and executed no application or DB checks. Integrated HTTP/context/observer cleanup and final tested-source/sealed-evidence approval remain pending. Adjacent V1/generic/OriginalCommitWitness/membership scopes stay deferred.

The JSON binds scoped immutable source hashes and the exact remaining trace. No shared edit, PHP/app/DB/network/CI execution or preparation failure occurred in this follow-up.
