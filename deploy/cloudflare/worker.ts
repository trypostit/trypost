interface Env {
    ORIGIN_IP: string;
}

export default {
    fetch(request: Request, env: Env): Promise<Response> {
        const url = new URL(request.url);
        url.protocol = 'http:';
        url.hostname = env.ORIGIN_IP;
        url.port = '80';

        return fetch(new Request(url, request));
    },
} satisfies ExportedHandler<Env>;
