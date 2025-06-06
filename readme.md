Setup the remote client
=======================

The remote client exist to help users without a FullClient on their computer to play by downloading resources from an external server.
Because pushing directly the fullclient on a server/ftp can provoke some errors, this tool allow to :

 - Get the files from a client used in another domain (Cross-origin resource sharing).
 - Extracting files directly from GRF archive (only version 0x200 supported for now - without DES encryption).
 - Converting BMP files to PNG to speed up the transfer.
 - Optimized to don't call any script if files are already extracted/converted (resource friendly).

###Add your fullclient###

Just put your GRFs files and DATA.INI file in the `resources/` directory.
Overwrite the `BGM/`, `data/` and `System/` directories with your own folders.

**Note: to be sure to use a compatible version of your GRFs, download *GRF Builder* and repack them manually (Option > Repack type > Decrypt -> Repack), it will ensure the GRFs files are converted in the proper version**

-----------------

###Configure the remote client###

Open *configs.php* file and complete it

----------------

###Configure the rewrite rule###

Open *".htaccess"* file, you will have some example on how to modify the rewrite rule to match your url location :

    #ex : myroserver.com/client/
    ErrorDocument 404 /client/index.php

    #ex : myroserver.com/low/client/
    ErrorDocument 404 /low/client/index.php

    #ex : client.myroserver.com/
    ErrorDocument 404 /index.php

## Using Docker container

You can use this setup with the container to run the remote client API. Using docker container does not handle/copy 
the game files. You need to setup them first on the directory.

Copy the file [.env.example](.env.example) to the same directory as .env

### Using docker compose

Start the services running the following command:

```bash
docker compose up -d
```

### Using docker cli

Build the container image:

```bash
docker build -t remote-client-api:latest .
```

Run the container:

```bash
docker run -d -p 80:80 --env-file .env -v ./:/var/www/html remote-client-api:latest
```
