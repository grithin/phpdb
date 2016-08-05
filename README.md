# PHP Database Tools

## Purpose

Provide easy access methods, common database output formatting and standard input handling.

## Use

### Connecting

```php
use \Grithin\Db;

$dbConfig = ['user'=>'root',
	'password'=>'',
	'database'=>'feed',
	'host'=>'localhost',
	'driver'=>'mysql'	];


$db = Db::init('main', $dbConfig);

$dbConfig = ['user'=>'root',
	'password'=>'',
	'database'=>'test',
	'host'=>'localhost',
	'driver'=>'mysql'	];


$db2 = Db::init('secondary', $dbConfig);
```

The first argument to init is the name of the singleton instance (see SingletonDefault or SDLL class in phpbase).

When Db is called statically, it defaults to the primary singleton instance, which is usually the first initialised.
```php
$db1 = Db::init(null, $config1);
Db::row('user',$id); # on $db1 instance
$db1->row('user',$id);

$db2 = Db::init('secondary', $config2);
Db::row('user',$id); # still on $db1 instance
$db2->row('user',$id);

Db::setPrimary('secondary');
Db::row('user',$id); # on $db2 instance
```
If you want a instance variable for  the primary singleton (the singleton default), you can get it
```php
$db =  Db::primary();
```
This is useful for looped code (since there is overhead in using static methods).

You can also get an instance by name
```php
$db = Db::$instances['main'];
```


### Basic Use

There are two flavors of use
1.	quoted queries
2.	prepared statements

#### Query
##### Quoting
All user input placed in raw sql should be quoted or conformed.  You can quote with
```php
'select * from user where id = '.Db::quote($id);
```

You can also quote database identities, with
```php
'select '.Db::quoteIdentity('table.max').' from '.Db::quoteIdentity('table').' where id = 1'
```
This is sometimes useful when the identity has a name conflicting with a reserved database identity

##### SQL
```php
Db::value('select name from user where id = 1');
#> 'bob'

Db::row('select * from user'); # will append a `limit 1`
#> ['id'=>'1','name'=>'bob']

Db::rows('select * from user');
#> [['id'=>'1','name'=>'bob'], ['id'=>'2','name'=>'bill']]

Db::column('select name from user');
#> ['bob','bill']

Db::column('select * from user');
#> [['1','bob'],[['2','bill']]

list($id, $name) =  Db::enumerate('select id, name from user');

Db::columnKey('id','select id, name from user');
#> ['1'=>'bob', '2'=>'bill']
# if more than 2 columns are selected, the key points to another array, ex:
#	#> ['1'=>['name'=>'bob', 'gender'=>'m'], '2'=>['name'=>'bill', 'gender'=>'m']]
```

##### Shortcuts
Short cut parameters are automatically quoted if necessary
```php
Db::row('user',1); # select * from user where id = 1
Db::row('user',['id'=>1]); # select * from user where id = 1

Db::insert('user',['name'=>'jill']);

Db::insertIgnore('user',['name'=>'jill']);
Db::insertUpdate('user',['id'=>'3','name'=>'jill']);

Db::replace('user',['id'=>'2', 'name'=>'jill']);

Db::update('user',['name'=>'jan'], ['id'=>3]);

Db::delete('user',1);
Db::delete('user',['id'=>1]);
Db::delete('user',['id?>'=>1]);
```

There are many additional helper functions like the above.  I recommended looking through the code.  Create a github issue if you require one to be further documented.

###### Shortcut Magic

Using a custom comparater
```php
# `>` is used
Db::row('user', ['id?>'=>1]); # select * from user where id > 1
```
There is various behavior based on these rules:
-	if key starts with '"', unescaped value taken as entire where line
	-	ex: ['"':'1=1']
-	if "?" is in the key, the part after the "?" will serve as the "equator", ("bob?<>"=>'sue') -> "bob <> 'sue'"
-	if key starts with ":", value is not escaped
-	if value === null, set value to unescaped "null"
-	if value set to unescaped "null", and if within a "where" helper function, and comparater is '=', prefix with 'is '.


#### Prepared Statements
The `exec` function is overloaded to accept three forms.  The end result of the forms is to have one joined sql string and one merged variable array.
-	1 parameter:
	-	`[< sql string or variable array >,...]`
-	2 parameters:
	-	$sql, $variables
-	>2 parameters
	-	mix of $sql and $variables

```php
# -	1 param:
$pdo_statement = $db->exec(['select * from user where id = :id',[':id'=>1]])
# -	2 params:
$pdo_statement = $db->exec('select * from user where id = :id',[':id'=>1])
# -	>2 params:
$pdo_statement = $db->exec('select * from','user where id = :id',[':id'=>1],'and id = :id2',[':id2'=>1] );
```

You can either use pdo statment methods, or you can use `Db` helper functions.  Most of the `query` based functions have corresponding methods prefixed with `as_`
```php
$pdo_statement = $db->exec(['select * from user where id = :id',[':id'=>1]])
$db->as_row($pdo_statement);
$db->as_rows($pdo_statement);
$db->as_value($pdo_statement);
```
