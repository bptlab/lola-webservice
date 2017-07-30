# Low-level petri net analyzer webservice

This is a Docker context to build an image for a container that exposes LolA as a webservice.
Mostly this is re-structuring, patching and hacking to get a version to work again that was running "as-is" on one of our servers. I don't have too much insight into the inner workings of LolA and the wrapper scripts.

The base image runs php on nginx. A php script reads user input (a *.pnml* file), converts it to *.owfn* format, runs different analyses on it, gathers the results and outputs one result page.

This still uses LolA version `1.18`, because `2.x` is completely differently structured and I couldn't get it to run.
