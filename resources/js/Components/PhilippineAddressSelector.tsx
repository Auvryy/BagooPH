import React, { useEffect, useState } from 'react';
import InputError from '@/Components/InputError';
import {
    getProvinces,
    getCities,
    getBarangays,
} from '@/services/psgcService';
import {
    ProvinceOption,
    CityOption,
    BarangayOption,
} from '@/data/philippineLocations';
import { MapPin, Building, Home, Loader2 } from 'lucide-react';

interface AddressValues {
    province: string;
    city: string;
    municipality: string;
    barangay: string;
    address: string;
}

interface PhilippineAddressSelectorProps {
    values: AddressValues;
    onChange: (values: AddressValues) => void;
    errors?: Partial<Record<keyof AddressValues, string>>;
    accentColor?: 'primary' | 'emerald';
    required?: boolean;
    showStreetAddress?: boolean;
    streetLabel?: string;
    streetPlaceholder?: string;
}

export default function PhilippineAddressSelector({
    values,
    onChange,
    errors = {},
    accentColor = 'primary',
    required = true,
    showStreetAddress = true,
    streetLabel = 'Street / House / Unit No.',
    streetPlaceholder = 'e.g. Unit 401, 123 Katipunan Ave',
}: PhilippineAddressSelectorProps) {
    const [provinces, setProvinces] = useState<ProvinceOption[]>([]);
    const [cities, setCities] = useState<CityOption[]>([]);
    const [barangays, setBarangays] = useState<BarangayOption[]>([]);

    const [selectedProvinceObj, setSelectedProvinceObj] = useState<ProvinceOption | null>(null);
    const [selectedCityObj, setSelectedCityObj] = useState<CityOption | null>(null);

    const [loadingProvinces, setLoadingProvinces] = useState(false);
    const [loadingCities, setLoadingCities] = useState(false);
    const [loadingBarangays, setLoadingBarangays] = useState(false);

    // Initial load of provinces
    useEffect(() => {
        let mounted = true;
        setLoadingProvinces(true);
        getProvinces().then((data) => {
            if (mounted) {
                setProvinces(data);
                setLoadingProvinces(false);

                // If existing province in values, find matching province
                if (values.province) {
                    const match = data.find(
                        (p) =>
                            p.name.toLowerCase() === values.province.toLowerCase() ||
                            p.name.toLowerCase().includes(values.province.toLowerCase()) ||
                            values.province.toLowerCase().includes(p.name.toLowerCase())
                    );
                    if (match) {
                        setSelectedProvinceObj(match);
                    }
                }
            }
        });

        return () => {
            mounted = false;
        };
    }, []);

    // Load cities when province changes
    useEffect(() => {
        if (!selectedProvinceObj) {
            setCities([]);
            setSelectedCityObj(null);
            return;
        }

        let mounted = true;
        setLoadingCities(true);
        getCities(selectedProvinceObj).then((data) => {
            if (mounted) {
                setCities(data);
                setLoadingCities(false);

                // If existing city in values, find matching city
                if (values.city) {
                    const match = data.find(
                        (c) =>
                            c.name.toLowerCase() === values.city.toLowerCase() ||
                            c.name.toLowerCase().includes(values.city.toLowerCase()) ||
                            values.city.toLowerCase().includes(c.name.toLowerCase())
                    );
                    if (match) {
                        setSelectedCityObj(match);
                    }
                }
            }
        });

        return () => {
            mounted = false;
        };
    }, [selectedProvinceObj]);

    // Load barangays when city changes
    useEffect(() => {
        if (!selectedCityObj) {
            setBarangays([]);
            return;
        }

        let mounted = true;
        setLoadingBarangays(true);
        getBarangays(selectedCityObj).then((data) => {
            if (mounted) {
                setBarangays(data);
                setLoadingBarangays(false);
            }
        });

        return () => {
            mounted = false;
        };
    }, [selectedCityObj]);

    const handleProvinceChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const provCode = e.target.value;
        const provObj = provinces.find((p) => p.code === provCode) || null;
        setSelectedProvinceObj(provObj);
        setSelectedCityObj(null);
        setBarangays([]);

        const provinceName = provObj ? (provObj.code === 'NCR' ? 'Metro Manila' : provObj.name) : '';

        onChange({
            ...values,
            province: provinceName,
            city: '',
            municipality: '',
            barangay: '',
        });
    };

    const handleCityChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const cityCode = e.target.value;
        const cityObj = cities.find((c) => c.code === cityCode) || null;
        setSelectedCityObj(cityObj);
        setBarangays([]);

        const cityName = cityObj ? cityObj.name : '';

        onChange({
            ...values,
            city: cityName,
            municipality: cityName,
            barangay: '',
        });
    };

    const handleBarangayChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        onChange({
            ...values,
            barangay: e.target.value,
        });
    };

    const handleStreetChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        onChange({
            ...values,
            address: e.target.value,
        });
    };

    const focusBorderClass =
        accentColor === 'emerald'
            ? 'focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600'
            : 'focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]';

    return (
        <div className="space-y-3">
            {/* Row 1: Province / Region & City / Municipality */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {/* Province Dropdown */}
                <div>
                    <div className="h-5 flex items-center mb-1">
                        <label className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate">
                            Province / Region {required && <span className="text-[#E00D42]">*</span>}
                        </label>
                    </div>
                    <div className="relative">
                        <select
                            id="province"
                            name="province"
                            value={selectedProvinceObj?.code || ''}
                            onChange={handleProvinceChange}
                            disabled={loadingProvinces}
                            className={`w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg outline-hidden transition text-slate-900 ${focusBorderClass} ${
                                loadingProvinces ? 'opacity-60 cursor-wait' : ''
                            }`}
                            required={required}
                        >
                            <option value="">
                                {loadingProvinces ? 'Loading provinces...' : 'Select Province / Region'}
                            </option>
                            {provinces.map((prov) => (
                                <option key={prov.code} value={prov.code}>
                                    {prov.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    {errors.province && <InputError message={errors.province} className="mt-1" />}
                </div>

                {/* City / Municipality Dropdown */}
                <div>
                    <div className="h-5 flex items-center justify-between mb-1">
                        <label className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate">
                            City / Municipality {required && <span className="text-[#E00D42]">*</span>}
                        </label>
                        {loadingCities && (
                            <span className="text-[10px] text-slate-400 font-mono flex items-center gap-1">
                                <Loader2 className="w-3 h-3 animate-spin text-slate-500" />
                                Loading
                            </span>
                        )}
                    </div>
                    <div className="relative">
                        <select
                            id="city"
                            name="city"
                            value={selectedCityObj?.code || ''}
                            onChange={handleCityChange}
                            disabled={!selectedProvinceObj || loadingCities}
                            className={`w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg outline-hidden transition text-slate-900 ${focusBorderClass} ${
                                !selectedProvinceObj || loadingCities ? 'opacity-60 cursor-not-allowed bg-slate-50' : ''
                            }`}
                            required={required}
                        >
                            <option value="">
                                {!selectedProvinceObj
                                    ? 'Select province first'
                                    : loadingCities
                                    ? 'Fetching cities...'
                                    : 'Select City / Municipality'}
                            </option>
                            {cities.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    {(errors.city || errors.municipality) && (
                        <InputError message={errors.city || errors.municipality} className="mt-1" />
                    )}
                </div>
            </div>

            {/* Row 2: Barangay & Street Address */}
            <div className="grid grid-cols-1 sm:grid-cols-12 gap-3">
                {/* Barangay Dropdown */}
                <div className={showStreetAddress ? 'sm:col-span-5' : 'sm:col-span-12'}>
                    <div className="h-5 flex items-center justify-between mb-1">
                        <label className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate">
                            Barangay {required && <span className="text-[#E00D42]">*</span>}
                        </label>
                        {loadingBarangays && (
                            <span className="text-[10px] text-slate-400 font-mono flex items-center gap-1">
                                <Loader2 className="w-3 h-3 animate-spin text-slate-500" />
                                Loading
                            </span>
                        )}
                    </div>
                    <div className="relative">
                        {barangays.length > 0 ? (
                            <select
                                id="barangay"
                                name="barangay"
                                value={values.barangay}
                                onChange={handleBarangayChange}
                                disabled={!selectedCityObj || loadingBarangays}
                                className={`w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg outline-hidden transition text-slate-900 ${focusBorderClass} ${
                                    !selectedCityObj || loadingBarangays ? 'opacity-60 cursor-not-allowed bg-slate-50' : ''
                                }`}
                                required={required}
                            >
                                <option value="">
                                    {!selectedCityObj
                                        ? 'Select city first'
                                        : loadingBarangays
                                        ? 'Fetching barangays...'
                                        : 'Select Barangay'}
                                </option>
                                {barangays.map((b) => (
                                    <option key={b.code} value={b.name}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <input
                                id="barangay"
                                type="text"
                                name="barangay"
                                value={values.barangay}
                                onChange={(e) => onChange({ ...values, barangay: e.target.value })}
                                disabled={!selectedCityObj}
                                placeholder={!selectedCityObj ? 'Select city first' : 'Enter Barangay'}
                                className={`w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg outline-hidden transition text-slate-900 placeholder-slate-400 ${focusBorderClass} ${
                                    !selectedCityObj ? 'opacity-60 cursor-not-allowed bg-slate-50' : ''
                                }`}
                                required={required}
                            />
                        )}
                    </div>
                    {errors.barangay && <InputError message={errors.barangay} className="mt-1" />}
                </div>

                {/* Street Address / House No. */}
                {showStreetAddress && (
                    <div className="sm:col-span-7">
                        <div className="h-5 flex items-center mb-1">
                            <label className="text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono truncate">
                                {streetLabel} {required && <span className="text-[#E00D42]">*</span>}
                            </label>
                        </div>
                        <div className="relative">
                            <input
                                id="address"
                                type="text"
                                name="address"
                                value={values.address}
                                onChange={handleStreetChange}
                                placeholder={streetPlaceholder}
                                className={`w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg outline-hidden transition text-slate-900 placeholder-slate-400 ${focusBorderClass}`}
                                required={required}
                            />
                        </div>
                        {errors.address && <InputError message={errors.address} className="mt-1" />}
                    </div>
                )}
            </div>
        </div>
    );
}
