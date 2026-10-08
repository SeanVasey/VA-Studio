import sys
name=sys.argv[1]
F='<snapshot of the hardened tree>/app/Domain/Commerce/ProductionTaxCheckout/TaxCheckoutSchemaInstaller.php'
M={
 'M1-foreign-key-consumer': ("                $this->reject('foreign key consumer');\n", ""),
 'M2-additional-owned-guard': ("                    $this->reject('additional owned guard');\n", ""),
 'M3-foreign-view': ("            if ($view->VIEW_DEFINITION === null || $this->referencesOwned($view->VIEW_DEFINITION)) {\n                $this->reject('foreign view');\n            }\n", ""),
}
old,new=M[name]
s=open(F).read()
assert s.count(old)==1, name
open(F,'w').write(s.replace(old,new))
