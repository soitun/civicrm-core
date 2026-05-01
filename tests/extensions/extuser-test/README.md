# extuser-test

Example extension that integrates support for an external user-credential (such as LDAP or flat file).

General goals of the test are:

* Listen to events like `civi.standalone.loadUser` and `civi.standalone.checkPassword`.
* Create some sample user (username/password) with the hook.
* Use PHPUnit to login (with the username/password).
* Be relatively simple.
* If a developer/evaluator accidentally enables this on staging, don't create an obvious vulnerability (like a static password).

The sample user will have this convention:

* The file `extuser_test.json` contains the list of sample users.
* The passwords are computed as `JWT(scope=>extuser-test, sub=>username)`.
