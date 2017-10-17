# LoLA 2 as a service

This is a Docker context to build an image for a container that exposes LoLA 2.0 as a webservice.

## How to use
* Build a Docker image: `docker build -t bpt/lola .`
* Run as Docker container and bind to local port 8080: `docker run --rm -it --name lola -p 8080:80 bpt/lola`
* Navigate to `localhost:8080`, select a PNML file, select checks and run

## The `lola.php` wrapper script
The service wrapper (`lola.php`) will do the following:

* read user input as *PNML*
* convert to *lola* format using `petri`
* parse the *.lola* file (soundness checks with LoLA 2.0 require prior knowledge of source and sink place(s))
* run selected checks
* display the check results together with a witness path, if there is any

## The provided `Dockerfile`
When building, it will

* base on a php/nginx image
* install required dependencies
* patch and build LoLA 1.18 (needed for petri tool)
* build LoLA 2.0
* build petri
