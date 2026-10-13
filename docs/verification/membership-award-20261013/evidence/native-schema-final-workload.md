# Native schema selection workload note

The ten-case native schema selection began on the exclusively owned `vaseyaudio_membership_award` schema of the private MySQL 8.4.11 instance. After the first completed case, root authorized its three native award races to run concurrently on separately owned `vaseyaudio_membership_award_profile` on the same instance. The schema selection and race suite have separate schema ownership but share server CPU, memory, and system dictionaries. Source remains frozen. Measured elapsed times therefore include simultaneous local verification workload; any observed failure must be retained and assessed without describing these runs as unloaded benchmarks.

This note records coordination only. `native-schema-final.txt`, `.xml`, `.exit`, `.metadata.json`, `.sources.json`, and `-source-check.txt` contain actual completion evidence when the running selection exits. No product/test source is modified by this note.
