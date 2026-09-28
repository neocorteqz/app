# Other web server integration

The installer leaves unknown web-server configuration untouched and runs a helper that prints the required reverse-proxy target and headers. Keep the ApexNode listener bound to loopback and configure your own web server to proxy to `http://127.0.0.1:<panel-port>`.
