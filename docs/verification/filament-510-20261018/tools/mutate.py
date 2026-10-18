#!/usr/bin/env python3
"""usage: mutate.py <name>  -- apply a leak mutation to app code (revert with git checkout app)"""
import sys
R='/home/user/wt/m3/app/Filament/Resources/'
M={
 'membership':(R+'MembershipPlanResource/Pages/ManageMembershipPlans.php',
   "reviewing again.')->persistent()->send();\n            $this->formErrors('The result could not be confirmed.",
   "reviewing again.'.$exception->getMessage())->persistent()->send();\n            $this->formErrors('The result could not be confirmed."),
 'template':(R+'LicenseTemplateResource/Pages/ManageLicenseTemplates.php',
   "->body('Reload templates and inspect the current saved identity before trying again.')->persistent()->send();\n    }",
   "->body('Reload templates and inspect the current saved identity before trying again.'.$exception->getMessage())->persistent()->send();\n    }"),
 'draft':(R+'LicenseVersionResource/Pages/ManageLicenseVersions.php',
   "inspect the saved draft before trying again.')->persistent()->send();\n    }\n\n    protected function getHeaderActions",
   "inspect the saved draft before trying again.'.$exception->getMessage())->persistent()->send();\n    }\n\n    protected function getHeaderActions"),
 'bulk':(R+'LicenseVersionResource/Pages/ManageLicenseVersions.php',
   "inspect the saved drafts before trying again.')->persistent()->send();\n            $this->bulkSourceErrors",
   "inspect the saved drafts before trying again.'.$exception->getMessage())->persistent()->send();\n            $this->bulkSourceErrors"),
 'offer':(R+'OfferResource/Pages/ManageOffers.php',
   "inspect the saved draft before trying again.')->persistent()->send();\n            $this->formErrors(ValidationException::withMessages(['offer'",
   "inspect the saved draft before trying again.'.$exception->getMessage())->persistent()->send();\n            $this->formErrors(ValidationException::withMessages(['offer'"),
 'rights':(R+'RightsDeclarationResource/Pages/ManageRightsDeclarations.php',
   "->body('Reload declarations and review current evidence before trying again.')->persistent()->send();\n\n            return null;",
   "->body('Reload declarations and review current evidence before trying again.'.$exception->getMessage())->persistent()->send();\n\n            return null;"),
}
f,old,new=M[sys.argv[1]]
s=open(f).read()
assert s.count(old)==1,(sys.argv[1],s.count(old))
open(f,'w').write(s.replace(old,new))
print('mutated',sys.argv[1])
