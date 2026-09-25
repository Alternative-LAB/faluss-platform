import http.server,http.client,ssl
class Proxy(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self): self.forward()
    def do_POST(self): self.forward()
    def forward(self):
        ports={'faluss.me':8101,'fans.local.test':8102}
        port=ports.get(self.headers.get('Host'))
        if not port: self.send_error(403); return
        conn=http.client.HTTPConnection('127.0.0.1',port,timeout=40)
        body=self.rfile.read(int(self.headers.get('Content-Length',0)))
        conn.request(self.command,self.path,body,dict(self.headers))
        response=conn.getresponse(); payload=response.read(); self.send_response(response.status)
        for k,v in response.getheaders():
            if k.lower() not in ['transfer-encoding','connection','content-length']: self.send_header(k,v)
        self.send_header('Content-Length',str(len(payload)))
        self.end_headers(); self.wfile.write(payload); conn.close()
server=http.server.ThreadingHTTPServer(('127.0.0.1',443),Proxy)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/var/tmp/faluss-fans-http/cert.pem','/var/tmp/faluss-fans-http/key.pem')
server.socket=context.wrap_socket(server.socket,server_side=True); server.serve_forever()
