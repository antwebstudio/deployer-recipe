# deployer-recipe

```
composer require antweb/deployer-recipe
```

Make sure you have install deployer using this command
```
curl -LO https://deployer.org/deployer.phar
mv deployer.phar /usr/local/bin/dep
chmod +x /usr/local/bin/dep
```
reference: https://deployer.org/docs/installation.html

Run these command in project folder root (either linux or windows terminal will be fine)
```
cp ./vendor/antweb/deployer-recipe/hosts.example.yml ./hosts.yml
cp ./vendor/antweb/deployer-recipe/deploy.example.php ./deploy.php
```

Add the following line to .gitignore (create one if the file is not exist)
```
/hosts.yml
```

Edit the hosts.yml file and deploy.php file accordingly

Run
```
dep deploy beta
```
