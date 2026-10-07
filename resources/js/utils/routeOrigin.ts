interface RouteOrigin {
    url: string;
    port: number | null;
}

export function configureRouteOrigin<T extends RouteOrigin>(config: T, location: Pick<Location, 'origin' | 'port'>): T {
    config.url = location.origin;
    config.port = location.port ? Number(location.port) : null;
    return config;
}
