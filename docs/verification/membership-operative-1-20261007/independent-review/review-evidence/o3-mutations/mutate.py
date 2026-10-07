import sys,re
path,mode=sys.argv[1],sys.argv[2]
src=open(path).read()
if 'MemberGrant' in path: ex='MemberGrantException'
else: ex='MembershipException'
if mode=='old_instance_read':
    src=src.replace("$environment = $this->environment($instances);","$environment = $instances['env'] ?? null;",1)
elif mode=='invoking_read':
    src=src.replace("$environment = $this->environment($instances);","$environment = $this->container->make('env');",1)
elif mode=='drop_changed_policy':
    a=src.index(ex+"::require($concrete instanceof Closure, 'changed_policy');")
    b=src.index("return $variables['value'];",a)
    src=src[:a]+"$function = new ReflectionFunction($concrete);\n        $variables = $function->getStaticVariables();\n\n        "+"return $variables['value'] ?? null;"+src[b+len("return $variables['value'];"):]
else: sys.exit('bad mode')
open(path,'w').write(src)
