import {
    LOCAL_PROVINCES,
    LOCAL_CITIES,
    LOCAL_BARANGAYS,
    ProvinceOption,
    CityOption,
    BarangayOption,
} from '@/data/philippineLocations';

const PSGC_BASE_URL = 'https://psgc.gitlab.io/api';
const TIMEOUT_MS = 2500;

/**
 * Fetch helper with timeout
 */
async function fetchWithTimeout(url: string): Promise<any> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
        const res = await fetch(url, { signal: controller.signal });
        clearTimeout(timeoutId);
        if (!res.ok) throw new Error(`HTTP error ${res.status}`);
        return await res.json();
    } catch (err) {
        clearTimeout(timeoutId);
        throw err;
    }
}

/**
 * Get provinces list (with Metro Manila prioritized at the top).
 * Fetches from public PSGC API, cached in sessionStorage, with instant local fallback.
 */
export async function getProvinces(): Promise<ProvinceOption[]> {
    const cacheKey = 'bagoo_psgc_provinces';
    try {
        const cached = sessionStorage.getItem(cacheKey);
        if (cached) {
            return JSON.parse(cached);
        }
    } catch {
        // Ignore session storage errors
    }

    try {
        const remoteProvinces = await fetchWithTimeout(`${PSGC_BASE_URL}/provinces/`);
        if (Array.isArray(remoteProvinces) && remoteProvinces.length > 0) {
            // Sort alphabetically by name
            const sorted: ProvinceOption[] = remoteProvinces
                .map((p: any) => ({
                    code: p.code,
                    name: p.name,
                    region: p.regionCode,
                }))
                .sort((a, b) => a.name.localeCompare(b.name));

            // Prepend Metro Manila / NCR
            const fullList: ProvinceOption[] = [
                { code: 'NCR', name: 'Metro Manila (NCR)', region: '130000000' },
                ...sorted,
            ];

            try {
                sessionStorage.setItem(cacheKey, JSON.stringify(fullList));
            } catch {
                // Ignore storage errors
            }
            return fullList;
        }
    } catch {
        // Fall back gracefully to local static dataset
    }

    return LOCAL_PROVINCES;
}

/**
 * Get cities and municipalities for a given province.
 */
export async function getCities(province: ProvinceOption): Promise<CityOption[]> {
    const cacheKey = `bagoo_psgc_cities_${province.code}`;
    try {
        const cached = sessionStorage.getItem(cacheKey);
        if (cached) {
            return JSON.parse(cached);
        }
    } catch {
        // Ignore storage errors
    }

    // Special handling for Metro Manila / NCR
    if (province.code === 'NCR' || province.name.toLowerCase().includes('manila')) {
        try {
            const remoteCities = await fetchWithTimeout(`${PSGC_BASE_URL}/regions/130000000/cities-municipalities/`);
            if (Array.isArray(remoteCities) && remoteCities.length > 0) {
                const formatted: CityOption[] = remoteCities
                    .map((c: any) => ({
                        code: c.code,
                        name: c.name,
                        provinceCode: 'NCR',
                        provinceName: 'Metro Manila',
                    }))
                    .sort((a, b) => a.name.localeCompare(b.name));

                try {
                    sessionStorage.setItem(cacheKey, JSON.stringify(formatted));
                } catch {
                    // Ignore storage errors
                }
                return formatted;
            }
        } catch {
            // Fall back to local NCR cities
        }
        return LOCAL_CITIES.NCR || [];
    }

    // Check if province has local mock data first if it's a 3-letter local code
    if (LOCAL_CITIES[province.code]) {
        return LOCAL_CITIES[province.code];
    }

    // Otherwise fetch from PSGC API for provinces with numeric PSGC codes
    if (/^\d+$/.test(province.code)) {
        try {
            const remoteCities = await fetchWithTimeout(
                `${PSGC_BASE_URL}/provinces/${province.code}/cities-municipalities/`
            );
            if (Array.isArray(remoteCities) && remoteCities.length > 0) {
                const formatted: CityOption[] = remoteCities
                    .map((c: any) => ({
                        code: c.code,
                        name: c.name,
                        provinceCode: province.code,
                        provinceName: province.name,
                    }))
                    .sort((a, b) => a.name.localeCompare(b.name));

                try {
                    sessionStorage.setItem(cacheKey, JSON.stringify(formatted));
                } catch {
                    // Ignore storage errors
                }
                return formatted;
            }
        } catch {
            // Fall back
        }
    }

    return LOCAL_CITIES[province.code] || [];
}

/**
 * Get barangays for a given city / municipality.
 */
export async function getBarangays(city: CityOption): Promise<BarangayOption[]> {
    const cacheKey = `bagoo_psgc_brgy_${city.code}`;
    try {
        const cached = sessionStorage.getItem(cacheKey);
        if (cached) {
            return JSON.parse(cached);
        }
    } catch {
        // Ignore storage errors
    }

    // If local barangay mock exists
    if (LOCAL_BARANGAYS[city.code]) {
        return LOCAL_BARANGAYS[city.code];
    }

    // If city has a numeric PSGC code, query public API
    if (/^\d+$/.test(city.code)) {
        try {
            const remoteBarangays = await fetchWithTimeout(
                `${PSGC_BASE_URL}/cities-municipalities/${city.code}/barangays/`
            );
            if (Array.isArray(remoteBarangays) && remoteBarangays.length > 0) {
                const formatted: BarangayOption[] = remoteBarangays
                    .map((b: any) => ({
                        code: b.code,
                        name: b.name,
                        cityCode: city.code,
                        cityName: city.name,
                    }))
                    .sort((a, b) => a.name.localeCompare(b.name));

                try {
                    sessionStorage.setItem(cacheKey, JSON.stringify(formatted));
                } catch {
                    // Ignore storage errors
                }
                return formatted;
            }
        } catch {
            // Fall back
        }
    }

    // Try finding by city name match in local barangays
    const matchedKey = Object.keys(LOCAL_BARANGAYS).find((k) => {
        const brgyList = LOCAL_BARANGAYS[k];
        return brgyList && brgyList[0] && brgyList[0].cityName.toLowerCase() === city.name.toLowerCase();
    });

    if (matchedKey && LOCAL_BARANGAYS[matchedKey]) {
        return LOCAL_BARANGAYS[matchedKey];
    }

    return [];
}
