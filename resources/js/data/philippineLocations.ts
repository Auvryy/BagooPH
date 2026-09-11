export interface ProvinceOption {
    code: string;
    name: string;
    region?: string;
}

export interface CityOption {
    code: string;
    name: string;
    provinceCode: string;
    provinceName: string;
}

export interface BarangayOption {
    code: string;
    name: string;
    cityCode: string;
    cityName: string;
}

/**
 * Built-in local Philippine Provinces and Regions list.
 * Features Metro Manila at the top followed by alphabetical provinces.
 */
export const LOCAL_PROVINCES: ProvinceOption[] = [
    { code: 'NCR', name: 'Metro Manila (NCR)', region: 'National Capital Region' },
    { code: 'ABR', name: 'Abra', region: 'CAR' },
    { code: 'AGN', name: 'Agusan del Norte', region: 'Region XIII' },
    { code: 'AGS', name: 'Agusan del Sur', region: 'Region XIII' },
    { code: 'AKL', name: 'Aklan', region: 'Region VI' },
    { code: 'ALB', name: 'Albay', region: 'Region V' },
    { code: 'ANT', name: 'Antique', region: 'Region VI' },
    { code: 'APA', name: 'Apayao', region: 'CAR' },
    { code: 'AUR', name: 'Aurora', region: 'Region III' },
    { code: 'BAS', name: 'Basilan', region: 'BARMM' },
    { code: 'BAN', name: 'Bataan', region: 'Region III' },
    { code: 'BTN', name: 'Batanes', region: 'Region II' },
    { code: 'BTG', name: 'Batangas', region: 'Region IV-A' },
    { code: 'BEN', name: 'Benguet', region: 'CAR' },
    { code: 'BIL', name: 'Biliran', region: 'Region VIII' },
    { code: 'BOH', name: 'Bohol', region: 'Region VII' },
    { code: 'BUK', name: 'Bukidnon', region: 'Region X' },
    { code: 'BUL', name: 'Bulacan', region: 'Region III' },
    { code: 'CAG', name: 'Cagayan', region: 'Region II' },
    { code: 'CAN', name: 'Camarines Norte', region: 'Region V' },
    { code: 'CAS', name: 'Camarines Sur', region: 'Region V' },
    { code: 'CAM', name: 'Camiguin', region: 'Region X' },
    { code: 'CAP', name: 'Capiz', region: 'Region VI' },
    { code: 'CAT', name: 'Catanduanes', region: 'Region V' },
    { code: 'CAV', name: 'Cavite', region: 'Region IV-A' },
    { code: 'CEB', name: 'Cebu', region: 'Region VII' },
    { code: 'COM', name: 'Cotabato', region: 'Region XII' },
    { code: 'DAV', name: 'Davao de Oro', region: 'Region XI' },
    { code: 'DAS', name: 'Davao del Norte', region: 'Region XI' },
    { code: 'DAC', name: 'Davao del Sur', region: 'Region XI' },
    { code: 'DAO', name: 'Davao Occidental', region: 'Region XI' },
    { code: 'DAE', name: 'Davao Oriental', region: 'Region XI' },
    { code: 'DIN', name: 'Dinagat Islands', region: 'Region XIII' },
    { code: 'EAS', name: 'Eastern Samar', region: 'Region VIII' },
    { code: 'GUI', name: 'Guimaras', region: 'Region VI' },
    { code: 'IFU', name: 'Ifugao', region: 'CAR' },
    { code: 'ILN', name: 'Ilocos Norte', region: 'Region I' },
    { code: 'ILS', name: 'Ilocos Sur', region: 'Region I' },
    { code: 'ILI', name: 'Iloilo', region: 'Region VI' },
    { code: 'ISA', name: 'Isabela', region: 'Region II' },
    { code: 'KAL', name: 'Kalinga', region: 'CAR' },
    { code: 'LUN', name: 'La Union', region: 'Region I' },
    { code: 'LAG', name: 'Laguna', region: 'Region IV-A' },
    { code: 'LAN', name: 'Lanao del Norte', region: 'Region X' },
    { code: 'LAS', name: 'Lanao del Sur', region: 'BARMM' },
    { code: 'LEY', name: 'Leyte', region: 'Region VIII' },
    { code: 'MAG', name: 'Maguindanao del Norte', region: 'BARMM' },
    { code: 'MAS', name: 'Maguindanao del Sur', region: 'BARMM' },
    { code: 'MAD', name: 'Marinduque', region: 'MIMAROPA' },
    { code: 'MSB', name: 'Masbate', region: 'Region V' },
    { code: 'MIC', name: 'Misamis Occidental', region: 'Region X' },
    { code: 'MIR', name: 'Misamis Oriental', region: 'Region X' },
    { code: 'MOU', name: 'Mountain Province', region: 'CAR' },
    { code: 'NEC', name: 'Negros Occidental', region: 'Region VI' },
    { code: 'NER', name: 'Negros Oriental', region: 'Region VII' },
    { code: 'NSA', name: 'Northern Samar', region: 'Region VIII' },
    { code: 'NUE', name: 'Nueva Ecija', region: 'Region III' },
    { code: 'NUV', name: 'Nueva Vizcaya', region: 'Region II' },
    { code: 'MDC', name: 'Occidental Mindoro', region: 'MIMAROPA' },
    { code: 'MDR', name: 'Oriental Mindoro', region: 'MIMAROPA' },
    { code: 'PLW', name: 'Palawan', region: 'MIMAROPA' },
    { code: 'PAM', name: 'Pampanga', region: 'Region III' },
    { code: 'PAN', name: 'Pangasinan', region: 'Region I' },
    { code: 'QUE', name: 'Quezon', region: 'Region IV-A' },
    { code: 'QUI', name: 'Quirino', region: 'Region II' },
    { code: 'RIZ', name: 'Rizal', region: 'Region IV-A' },
    { code: 'ROM', name: 'Romblon', region: 'MIMAROPA' },
    { code: 'SAM', name: 'Samar', region: 'Region VIII' },
    { code: 'SAR', name: 'Sarangani', region: 'Region XII' },
    { code: 'SIQ', name: 'Siquijor', region: 'Region VII' },
    { code: 'SOR', name: 'Sorsogon', region: 'Region V' },
    { code: 'SCO', name: 'South Cotabato', region: 'Region XII' },
    { code: 'SLE', name: 'Southern Leyte', region: 'Region VIII' },
    { code: 'SKU', name: 'Sultan Kudarat', region: 'Region XII' },
    { code: 'SLU', name: 'Sulu', region: 'BARMM' },
    { code: 'SUN', name: 'Surigao del Norte', region: 'Region XIII' },
    { code: 'SUR', name: 'Surigao del Sur', region: 'Region XIII' },
    { code: 'TAR', name: 'Tarlac', region: 'Region III' },
    { code: 'TAW', name: 'Tawi-Tawi', region: 'BARMM' },
    { code: 'ZMB', name: 'Zambales', region: 'Region III' },
    { code: 'ZAN', name: 'Zamboanga del Norte', region: 'Region IX' },
    { code: 'ZAS', name: 'Zamboanga del Sur', region: 'Region IX' },
    { code: 'ZSI', name: 'Zamboanga Sibugay', region: 'Region IX' },
];

/**
 * Built-in fallback cities for common regions and provinces.
 */
export const LOCAL_CITIES: Record<string, CityOption[]> = {
    NCR: [
        { code: 'NCR_MNL', name: 'City of Manila', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_QC', name: 'Quezon City', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_CAL', name: 'Caloocan', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_LP', name: 'Las Piñas', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_MKT', name: 'Makati City', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_MAL', name: 'Malabon', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_MDL', name: 'Mandaluyong', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_MAR', name: 'Marikina', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_MUN', name: 'Muntinlupa', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_NAV', name: 'Navotas', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_PAR', name: 'Parañaque', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_PAS', name: 'Pasay City', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_PSG', name: 'Pasig City', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_PAT', name: 'Pateros', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_SJ', name: 'San Juan', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_TAG', name: 'Taguig City', provinceCode: 'NCR', provinceName: 'Metro Manila' },
        { code: 'NCR_VAL', name: 'Valenzuela', provinceCode: 'NCR', provinceName: 'Metro Manila' },
    ],
    CEB: [
        { code: 'CEB_CEB', name: 'Cebu City', provinceCode: 'CEB', provinceName: 'Cebu' },
        { code: 'CEB_MAN', name: 'Mandaue City', provinceCode: 'CEB', provinceName: 'Cebu' },
        { code: 'CEB_LAP', name: 'Lapu-Lapu City', provinceCode: 'CEB', provinceName: 'Cebu' },
        { code: 'CEB_TAL', name: 'Talisay City', provinceCode: 'CEB', provinceName: 'Cebu' },
        { code: 'CEB_TOL', name: 'Toledo City', provinceCode: 'CEB', provinceName: 'Cebu' },
    ],
    DAC: [
        { code: 'DAC_DAV', name: 'Davao City', provinceCode: 'DAC', provinceName: 'Davao del Sur' },
        { code: 'DAC_DIG', name: 'Digos City', provinceCode: 'DAC', provinceName: 'Davao del Sur' },
        { code: 'DAC_STA', name: 'Santa Cruz', provinceCode: 'DAC', provinceName: 'Davao del Sur' },
    ],
    CAV: [
        { code: 'CAV_BAC', name: 'Bacoor City', provinceCode: 'CAV', provinceName: 'Cavite' },
        { code: 'CAV_DAS', name: 'Dasmariñas City', provinceCode: 'CAV', provinceName: 'Cavite' },
        { code: 'CAV_IMU', name: 'Imus City', provinceCode: 'CAV', provinceName: 'Cavite' },
        { code: 'CAV_TAG', name: 'Tagaytay City', provinceCode: 'CAV', provinceName: 'Cavite' },
        { code: 'CAV_GEN', name: 'General Trias', provinceCode: 'CAV', provinceName: 'Cavite' },
    ],
    LAG: [
        { code: 'LAG_STR', name: 'Santa Rosa City', provinceCode: 'LAG', provinceName: 'Laguna' },
        { code: 'LAG_CAL', name: 'Calamba City', provinceCode: 'LAG', provinceName: 'Laguna' },
        { code: 'LAG_BIN', name: 'Biñan City', provinceCode: 'LAG', provinceName: 'Laguna' },
        { code: 'LAG_SAN', name: 'San Pedro City', provinceCode: 'LAG', provinceName: 'Laguna' },
        { code: 'LAG_CAB', name: 'Cabuyao City', provinceCode: 'LAG', provinceName: 'Laguna' },
        { code: 'LAG_LOS', name: 'Los Baños', provinceCode: 'LAG', provinceName: 'Laguna' },
    ],
    RIZ: [
        { code: 'RIZ_ANT', name: 'Antipolo City', provinceCode: 'RIZ', provinceName: 'Rizal' },
        { code: 'RIZ_CAI', name: 'Cainta', provinceCode: 'RIZ', provinceName: 'Rizal' },
        { code: 'RIZ_TAY', name: 'Taytay', provinceCode: 'RIZ', provinceName: 'Rizal' },
        { code: 'RIZ_SNM', name: 'San Mateo', provinceCode: 'RIZ', provinceName: 'Rizal' },
        { code: 'RIZ_ANG', name: 'Angono', provinceCode: 'RIZ', provinceName: 'Rizal' },
    ],
    BUL: [
        { code: 'BUL_MAL', name: 'Malolos City', provinceCode: 'BUL', provinceName: 'Bulacan' },
        { code: 'BUL_MEY', name: 'Meycauayan City', provinceCode: 'BUL', provinceName: 'Bulacan' },
        { code: 'BUL_SJD', name: 'San Jose del Monte', provinceCode: 'BUL', provinceName: 'Bulacan' },
        { code: 'BUL_MAR', name: 'Marilao', provinceCode: 'BUL', provinceName: 'Bulacan' },
    ],
    PAM: [
        { code: 'PAM_SFE', name: 'San Fernando City', provinceCode: 'PAM', provinceName: 'Pampanga' },
        { code: 'PAM_ANG', name: 'Angeles City', provinceCode: 'PAM', provinceName: 'Pampanga' },
        { code: 'PAM_MAB', name: 'Mabalacat City', provinceCode: 'PAM', provinceName: 'Pampanga' },
    ],
    BTG: [
        { code: 'BTG_BAT', name: 'Batangas City', provinceCode: 'BTG', provinceName: 'Batangas' },
        { code: 'BTG_LIP', name: 'Lipa City', provinceCode: 'BTG', provinceName: 'Batangas' },
        { code: 'BTG_TAN', name: 'Tanauan City', provinceCode: 'BTG', provinceName: 'Batangas' },
        { code: 'BTG_STO', name: 'Santo Tomas', provinceCode: 'BTG', provinceName: 'Batangas' },
    ],
    BEN: [
        { code: 'BEN_BAG', name: 'Baguio City', provinceCode: 'BEN', provinceName: 'Benguet' },
        { code: 'BEN_LAT', name: 'La Trinidad', provinceCode: 'BEN', provinceName: 'Benguet' },
    ],
    ILI: [
        { code: 'ILI_ILO', name: 'Iloilo City', provinceCode: 'ILI', provinceName: 'Iloilo' },
        { code: 'ILI_PAS', name: 'Passi City', provinceCode: 'ILI', provinceName: 'Iloilo' },
        { code: 'ILI_OTO', name: 'Oton', provinceCode: 'ILI', provinceName: 'Iloilo' },
    ],
};

/**
 * Built-in fallback barangays for common cities.
 */
export const LOCAL_BARANGAYS: Record<string, BarangayOption[]> = {
    NCR_QC: [
        { code: 'QC_BAT', name: 'Batasan Hills', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_KAT', name: 'Loyola Heights (Katipunan)', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_DIL', name: 'UP Campus (Diliman)', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_CUB', name: 'Socorro (Cubao)', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_KAM', name: 'Kamuning', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_SNT', name: 'Santo Domingo', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_NEW', name: 'New Manila', cityCode: 'NCR_QC', cityName: 'Quezon City' },
        { code: 'QC_COM', name: 'Commonwealth', cityCode: 'NCR_QC', cityName: 'Quezon City' },
    ],
    NCR_PSG: [
        { code: 'PSG_SNA', name: 'San Antonio', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
        { code: 'PSG_ORT', name: 'San Antonio (Ortigas Center)', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
        { code: 'PSG_KAP', name: 'Kapitolyo', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
        { code: 'PSG_UGU', name: 'Ugong', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
        { code: 'PSG_ROS', name: 'Rosario', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
        { code: 'PSG_MAY', name: 'Maybunga', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
        { code: 'PSG_PIN', name: 'Pinagbuhatan', cityCode: 'NCR_PSG', cityName: 'Pasig City' },
    ],
    NCR_MKT: [
        { code: 'MKT_BEL', name: 'Bel-Air', cityCode: 'NCR_MKT', cityName: 'Makati City' },
        { code: 'MKT_SAN', name: 'San Lorenzo', cityCode: 'NCR_MKT', cityName: 'Makati City' },
        { code: 'MKT_POB', name: 'Poblacion', cityCode: 'NCR_MKT', cityName: 'Makati City' },
        { code: 'MKT_LEG', name: 'Legazpi Village', cityCode: 'NCR_MKT', cityName: 'Makati City' },
        { code: 'MKT_SAL', name: 'Salcedo Village', cityCode: 'NCR_MKT', cityName: 'Makati City' },
        { code: 'MKT_PIO', name: 'Pio del Pilar', cityCode: 'NCR_MKT', cityName: 'Makati City' },
        { code: 'MKT_PAL', name: 'Palanan', cityCode: 'NCR_MKT', cityName: 'Makati City' },
    ],
    NCR_TAG: [
        { code: 'TAG_BGC', name: 'Fort Bonifacio (BGC)', cityCode: 'NCR_TAG', cityName: 'Taguig City' },
        { code: 'TAG_USN', name: 'Ususan', cityCode: 'NCR_TAG', cityName: 'Taguig City' },
        { code: 'TAG_TUK', name: 'Tuktukan', cityCode: 'NCR_TAG', cityName: 'Taguig City' },
        { code: 'TAG_PIN', name: 'Pinagsama', cityCode: 'NCR_TAG', cityName: 'Taguig City' },
        { code: 'TAG_WST', name: 'Western Bicutan', cityCode: 'NCR_TAG', cityName: 'Taguig City' },
    ],
    NCR_MDL: [
        { code: 'MDL_HWY', name: 'Highway Hills', cityCode: 'NCR_MDL', cityName: 'Mandaluyong' },
        { code: 'MDL_WAC', name: 'Wack-Wack Greenhills', cityCode: 'NCR_MDL', cityName: 'Mandaluyong' },
        { code: 'MDL_PLN', name: 'Plainview', cityCode: 'NCR_MDL', cityName: 'Mandaluyong' },
        { code: 'MDL_BAR', name: 'Barangka Ilaya', cityCode: 'NCR_MDL', cityName: 'Mandaluyong' },
    ],
    NCR_MNL: [
        { code: 'MNL_ERM', name: 'Ermita', cityCode: 'NCR_MNL', cityName: 'City of Manila' },
        { code: 'MNL_MAL', name: 'Malate', cityCode: 'NCR_MNL', cityName: 'City of Manila' },
        { code: 'MNL_INT', name: 'Intramuros', cityCode: 'NCR_MNL', cityName: 'City of Manila' },
        { code: 'MNL_BIN', name: 'Binondo', cityCode: 'NCR_MNL', cityName: 'City of Manila' },
        { code: 'MNL_SAM', name: 'Sampaloc', cityCode: 'NCR_MNL', cityName: 'City of Manila' },
        { code: 'MNL_STA', name: 'Santa Cruz', cityCode: 'NCR_MNL', cityName: 'City of Manila' },
    ],
    CEB_CEB: [
        { code: 'CEB_LAU', name: 'Lahug (IT Park)', cityCode: 'CEB_CEB', cityName: 'Cebu City' },
        { code: 'CEB_MAB', name: 'Mabolo', cityCode: 'CEB_CEB', cityName: 'Cebu City' },
        { code: 'CEB_BAN', name: 'Banilad', cityCode: 'CEB_CEB', cityName: 'Cebu City' },
        { code: 'CEB_GUA', name: 'Guadalupe', cityCode: 'CEB_CEB', cityName: 'Cebu City' },
    ],
    DAC_DAV: [
        { code: 'DAV_POB', name: 'Poblacion', cityCode: 'DAC_DAV', cityName: 'Davao City' },
        { code: 'DAV_BUH', name: 'Buhangin', cityCode: 'DAC_DAV', cityName: 'Davao City' },
        { code: 'DAV_MAT', name: 'Matina', cityCode: 'DAC_DAV', cityName: 'Davao City' },
        { code: 'DAV_TAL', name: 'Talomo', cityCode: 'DAC_DAV', cityName: 'Davao City' },
    ],
    CAV_BAC: [
        { code: 'BAC_HAB', name: 'Habay I', cityCode: 'CAV_BAC', cityName: 'Bacoor City' },
        { code: 'BAC_PAN', name: 'Panapaan I', cityCode: 'CAV_BAC', cityName: 'Bacoor City' },
        { code: 'BAC_MOL', name: 'Molino III', cityCode: 'CAV_BAC', cityName: 'Bacoor City' },
    ],
    LAG_STR: [
        { code: 'STR_BAL', name: 'Balibago', cityCode: 'LAG_STR', cityName: 'Santa Rosa City' },
        { code: 'STR_DON', name: 'Don Jose', cityCode: 'LAG_STR', cityName: 'Santa Rosa City' },
        { code: 'STR_MAC', name: 'Macabling', cityCode: 'LAG_STR', cityName: 'Santa Rosa City' },
    ],
    RIZ_ANT: [
        { code: 'ANT_DEL', name: 'Dela Paz', cityCode: 'RIZ_ANT', cityName: 'Antipolo City' },
        { code: 'ANT_MAY', name: 'Mayamot', cityCode: 'RIZ_ANT', cityName: 'Antipolo City' },
        { code: 'ANT_SJR', name: 'San Jose', cityCode: 'RIZ_ANT', cityName: 'Antipolo City' },
    ],
};
