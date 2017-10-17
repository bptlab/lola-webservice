FROM maxexcloo/nginx-php:latest
MAINTAINER Adrian Holfter <adrian.holfter@student.hpi.uni-potsdam.de>

RUN apt-get update \
#    && apt-get upgrade -y \
    && apt-get install -y build-essential vim texinfo \
    && apt-get clean

# Copy "webapp" files
COPY index.html         /app/
COPY lola.php           /app/
COPY bootstrap.min.css  /app/

# Prepare dirs
RUN mkdir /opt/lola && \
    mkdir /opt/lola/bin && \
    mkdir /data/lola-workdir && \
    chown -R core /opt/lola && \
    chown -R core /data/lola-workdir

# Copy source files
COPY lola-1.18.tar.gz /opt/lola/
COPY lola-2.0.tar.gz /opt/lola/
COPY pnapi.tar.gz /opt/lola/
COPY formula.cc.patch /opt/lola/

# Unpack LoLA 1
# (LoLA 1 is still needed for pnapi)
RUN cd /opt/lola/ \
    && tar xvfz lola-1.18.tar.gz \
    && cd lola-1.18 \
    && mkdir build

# Patch one file
# HACK: Truncate documentation to avoid compiler errors due to probably outdated documentation markup
RUN cd /opt/lola \
    && patch lola-1.18/src/formula.cc formula.cc.patch \
    && echo "" > lola-1.18/doc/lola.texi

# Build LoLA 1
RUN cd /opt/lola/lola-1.18/build \
    && CXXFLAGS=-fpermissive ../configure --prefix=/opt/lola \
    && make -j4 all-configs \
    && make install

ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/lola/bin

#Build LoLA 2
RUN cd /opt/lola/ \
    && tar xvfz lola-2.0.tar.gz \
    && cd lola-2.0 \
    && mkdir build \
    && cd build \
    && ../configure \
    && make \
    && make install

# Build pnapi
RUN cd /opt/lola/ \
    && tar xvfz pnapi.tar.gz \
    && cd pnapi \
    && mkdir build \
    && cd build \
    && ../configure --prefix=/opt/lola \
    && make

# Install pnapi
RUN cd /opt/lola/pnapi/build \
    && make install \
    && cp utils/.libs/sound /opt/lola/bin/ \
    && find src -iname "*.so*" -exec cp {} /usr/lib/ \;
