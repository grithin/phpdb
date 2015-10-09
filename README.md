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

The first argument to init is the name of the instance (see SingletonDefault or SDLL class in phpbase).

You can call methods  on the "main" database with all of the following
```php
$db->row(...);
Db::row(...);
Db::primary()->row(...);
Db::$instances['main']->row(...);
```

The `Db::row` will access the primary singleton class.  Initializing a new instance with `Db::init` will not change the primary, this must be done with a separate method (see SingletonDefault)

The 'secondary' database can be used with
```php
$db2->row()
Db::$instances['secondary']->row(...);
```

### Overloaded Functions

Selector functions tend to be overloaded.  For example
```php
#atomatic limit of 1 applied to "row" method
$result = $db->row('select * from feed');
\Grithin\Debug::out($result);
#> ['id' : '1', 	...]

# a  single number as the  second parameter will be consider an id
$db->row('feed',1);
$lastSql = $db->lastSql();
\Grithin\Debug::out($lastSql);
#> SELECT * FROM "feed" WHERE id = 1 LIMIT 1'


# the where can be provided as an array
$db->row('feed',['id' => 1]);
$lastSql = $db->lastSql();
\Grithin\Debug::out($lastSql);
#> SELECT * FROM "feed" WHERE id = 1 LIMIT 1'

# the where array has various special key syntax to handle cases other than "=" comparisons
$db->row('feed', ['id ? >' => 1], 'id', 'id desc');
$lastSql = $db->lastSql();
\Grithin\Debug::out($lastSql);
#SELECT id FROM "feed" WHERE "id"  > '1' ORDER BY "id" desc LIMIT 1
```


### Quoting

If you are not using raw SQL, most values are automatically appropriately quoted.  

```php
$db->insert('user', ['name' => 'bob']);
```

If you are using raw sql, there are two methods
-	`quote` : for values
-	'quoteIdentity' : for identities
```php
$db->row('select '.Db::quoteIdentity('odd_field_name').' from '.Db::quoteIdentity('odd_table_name').' where name = '.Db::quote($userInput));
```
