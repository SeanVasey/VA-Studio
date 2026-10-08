Environment error, retained, not evidence about the code.
The externally terminated Guard run (03:31:00Z) left schema review_tax255_guard populated (345 triggers; db:wipe never ran).
CapabilityMigrationOwnership scans information_schema.TRIGGERS across every schema on the server, so the next
classes' migrate refused before any Tax255 code ran: NativeSchema 3 errors and Migration 10 errors, all
"Unexpected production capability external or additional table guard". Same class of problem the lane recorded in
evidence/native-env-error-shared-server/. Fix: the runner now drops every other review_tax255_* schema before each class.
