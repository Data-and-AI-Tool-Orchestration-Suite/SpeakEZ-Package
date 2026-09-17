# Oral Histroy Site
---

### Description
The template site is a staple of the Center for Applied AI and should be treated with respect. Yes, it uses PHP, 
but all in all it is a great starting point for a web project. In this 
integration, we use the LAPP stack which is an easy-to-learn, fast web dev experience.

### Requirements
1. Docker (and docker-compose)
2. A PostgreSQL client such as pgAdmin (optional, for administering the database)
3. PHP Composer (if making many websites based off this template, it is easier to install PHP composer on your host machine rather than installing it in the PHP container each time)

### Installation and Running
1. Check the docker-compose.yml file to change configs that suit your needs.
2. In ``` frontend/ ```, copy ``` config.php.example ``` to ``` config.php ``` and input your variables.
3. In ``` backend/postgres/```, copy ```init.sql.example``` to ```init.sql``` and add additional roles (if necessary, at the bottom of the file). Also, under the INSERT for roles there is another INSERT for an initial user, put your linkblue in the <linkblue> tag (i.e. abc123). You don't have to change the other parameters.
4. In the root of the project, copy ``` .env.example ``` to ``` .env ``` and input your variables. The database will be connected with these parameters, so be mindful.
5. Run ``` docker compose up -d ``` (or ```docker-compose``` depending on your docker version)
6. Run ``` docker ps ``` to get the first four characters of the PHP container hash (use it in the next step)
7. Run ``` docker exec -it #### /bin/sh ```
8. Run ``` composer install ``` while still in the docker container (if there is an error try ``` composer update ```)

### Adding a Plugin
1. Add handling to include the necessary controller/utility file in ``` frontend/routes.php ```
2. Add routes under the `PluginsController routes` section of ``` frontend/routes.php ```
3. Add the plugin details to ``` backend/postgres/init.sql ``` to add the plugin to the database
4. Add require once for the necessary model file in ``` frontend/models/Plugin.php ```
5. Add any specific handling required for activation/deactivation to the `updateActivation()` function in ``` frontend/models/Plugin.php ``` 

***NOTE***:
A database initialization file is provided in ```backend/postgres/init.sql```, feel free to change this to suit your needs. This script will run automatically on docker compose, and may take up to 30 seconds to complete once the container is running.

### License
We've chosen to use the GNU GPLv3 license for this software, but really we don't care what you do with it. Just give us some credit when you make it big.
