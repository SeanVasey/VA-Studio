<?php

// Production suppression 254: default-off and unbound. Root binds a separately reviewed provider
// adapter, scope and authority with Sean's authorization; no provider, credential or transport here.
return ['enabled' => false, 'provider' => null];
