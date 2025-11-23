/**
 * Keiste Solar Analysis - ROI Calculator
 * Financial calculations and UI updates
 * Version: 1.0.0
 */

(function () {
    'use strict';

    // ===== CONSTANTS =====
    const DAYS_IN_YR = 365.4;
    const MONTHS_IN_YR = 12.3;
    const HOURS_IN_DAY = 24;

    // Financial constants
    const CORPORATION_TAX = 0.125;        // 12.5%
    const SEAI_GRANT_RATE = 0.30;         // 30% grant rate
    const SEAI_GRANT_CAP = 162000;        // € limit
    const LOAN_APR_DEFAULT = 0.07;        // 7% APR
    const FEED_IN_TARIFF_DEFAULT = 0.21;  // €/kWh
    const COMPOUND_7_YRS = 1.07;
    const ANNUAL_INCREASE = 0.05;         // 5% bill increase
    const SOLAR_PANEL_DEGRADATION = 0.005;// 0.5%/yr
    const LENGTH_OF_PAYBACK = 7;          // loan length, years

    // Energy constants
    const PANEL_POWER_W = 400;            // W per panel
    const YRS_OF_SYSTEM = 25;
    const CO2_COEFFICIENT_TONNES = 0.0004;
    const DAY_POWER_AVG = 1.85;           // kWh/day per 400W panel

    // UI defaults
    const DEFAULT_PANELS = 4;
    const DEFAULT_EXPORT_PERCENT = 0.4;
    const DEFAULT_RETAIL_RATE = 0.35;
    const DEFAULT_FEED_IN_TARIFF = FEED_IN_TARIFF_DEFAULT;
    const DEFAULT_APR = LOAN_APR_DEFAULT;

    // ===== HELPER FUNCTIONS =====
    const byId = id => document.getElementById(id);
    
    const num = v => {
        if (v === null || v === undefined) return 0;
        const n = typeof v === 'number' ? v : parseFloat(String(v).replace(/[^\d.\-]/g, ''));
        return Number.isFinite(n) ? n : 0;
    };
    
    const clamp = (x, lo, hi) => Math.min(Math.max(x, lo), hi);

    function fmtEuro(x) {
        try {
            return (window.CURRENCY_SYMBOL || '') + Math.round(x).toLocaleString('en-IE');
        } catch {
            return `${window.CURRENCY_SYMBOL || ''}${Math.round(x).toLocaleString('en-IE')}`;
        }
    }

    function fmtNum(x, digits = 2) {
        return Number(x || 0).toLocaleString('en-IE', { maximumFractionDigits: digits });
    }

    window.formatCurrency = fmtEuro; // Expose for compatibility

    // ===== COST MODEL =====
    function estimateSolarCost(panels, batteryKWh = 0, includeDiverter = true) {
        const panelWatt = 400;
        const costPerKwP = 1200;
        const batteryCostPerKWh = 500;
        const diverterCost = 550;
        
        const systemKwP = (panels * panelWatt) / 1000;
        const panelCost = systemKwP * costPerKwP;
        const batteryCost = batteryKWh * batteryCostPerKWh;
        const diverter = includeDiverter ? diverterCost : 0;
        
        return panelCost + batteryCost + diverter;
    }

    // ===== INPUT READING =====
    function readInputs() {
        const inclGrant = !!(byId('inclGrant')?.checked);
        const inclACA = !!(byId('inclACA')?.checked);
        const inclLoan = !!(byId('inclLoan')?.checked);

        const panelCountEl = byId('panelCount');
        const panels = panelCountEl ? clamp(num(panelCountEl.value), 1, 10000) : DEFAULT_PANELS;

        const exportRate = (() => {
            const val = byId('exportRate')?.value;
            const p = num(val) / 100;
            return Number.isFinite(p) && p > 0 ? clamp(p, 0, 1) : DEFAULT_EXPORT_PERCENT;
        })();

        const electricityRate = (() => {
            const val = byId('electricityRate')?.value;
            const r = num(val);
            return r > 0 ? r : DEFAULT_RETAIL_RATE;
        })();

        const feedInTariff = FEED_IN_TARIFF_DEFAULT;

        const parsedLoanApr = (() => {
            const v = byId('loanApr')?.value;
            const n = Number.parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
            return Number.isFinite(n) && n > 0 ? n : LOAN_APR_DEFAULT;
        })();

        const APR = inclLoan ? parsedLoanApr : 0;

        const billMonthly = (() => {
            const val = byId('electricityBill')?.value;
            const r = Math.max(0, num(val));
            const roiBtnEl = document.getElementById('roiBtn');
            if (roiBtnEl) roiBtnEl.style.display = r > 0 ? 'block' : 'none';
            return r;
        })();

        // Energy production estimate
        let yearlyEnergy = 0;
        const availableConfigs = (Array.isArray(window.__parsedSolarConfigs) && window.__parsedSolarConfigs.length > 0)
            ? window.__parsedSolarConfigs
            : (typeof solarConfigs !== 'undefined' && Array.isArray(solarConfigs) && solarConfigs.length > 0 ? solarConfigs : []);

        if (availableConfigs.length > 0) {
            const extractKwh = (cfg) => {
                if (!cfg) return 0;
                const candidates = [
                    cfg['yearlyEnergyDcKwh'],
                    cfg['yearlyEnergy'],
                    cfg['Annual Energy Production'],
                    cfg['Annual Energy Production (kWh)']
                ];
                for (const cand of candidates) {
                    if (cand && typeof cand === 'string') {
                        const n = parseFloat(cand.replace(/[^0-9.\-]/g, '').replace(/,/g, ''));
                        if (Number.isFinite(n) && n > 0) return n;
                    }
                    if (Number.isFinite(cand) && cand > 0) return Number(cand);
                }
                return 0;
            };

            // Find exact match
            for (let i = 0; i < availableConfigs.length; i++) {
                const config = availableConfigs[i];
                const configPanels = parseInt(config['panelsCount'] || config['panels'] || 0);
                if (configPanels === panels) {
                    yearlyEnergy = extractKwh(config) || 0;
                    break;
                }
            }

            // Find closest lower if no exact match
            if (yearlyEnergy === 0) {
                let closestDiff = Infinity;
                for (let i = 0; i < availableConfigs.length; i++) {
                    const config = availableConfigs[i];
                    const configPanels = parseInt(config['panelsCount'] || config['panels'] || 0);
                    const diff = panels - configPanels;
                    if (diff >= 0 && diff < closestDiff) {
                        closestDiff = diff;
                        yearlyEnergy = extractKwh(config) || 0;
                    }
                }
            }
        }

        return { inclGrant, inclACA, inclLoan, panels, exportRate, electricityRate, feedInTariff, APR, billMonthly, yearlyEnergy };
    }

    // ===== KEY FIGURES CALCULATION =====
    function keyFigures(state) {
        const { inclGrant, inclACA, inclLoan, panels, exportRate, electricityRate: RETAIL, feedInTariff: FIT, APR, billMonthly, yearlyEnergy } = state;

        const kWp = (panels * PANEL_POWER_W) / 1000;
        const baseCost = Math.round(estimateSolarCost(state.panels));

        const seaiGrant = state.inclGrant ? Math.min(Number(baseCost * SEAI_GRANT_RATE), SEAI_GRANT_CAP) : 0;
        const acaAllowance = state.inclACA ? Math.min(Number(baseCost - seaiGrant), Number(baseCost) * CORPORATION_TAX) : 0;

        const interest = Math.min(Number(APR ? APR * LENGTH_OF_PAYBACK : 0) || 0, 0.5);

        const install_cost = Math.round(Number(baseCost));
        const net_install_cost = Math.round(Number(baseCost - seaiGrant + (inclLoan ? (baseCost - seaiGrant) * interest : 0)));

        const yearlyEnergyKWhYr0 = yearlyEnergy;
        const yearlyEnergyKWh = yearlyEnergyKWhYr0;

        // Loan calculations
        const m = 12, n = LENGTH_OF_PAYBACK * m;
        const principal = Math.max(0, baseCost - seaiGrant);
        const r = APR / m;
        const monthlyRepay = Math.round(inclLoan ? principal * (r / (1 - Math.pow(1 + r, -n))) : 0);
        const yearlyLoanCost = Math.round(inclLoan ? monthlyRepay * 12 : 0);

        const exportKWh_mo = yearlyEnergyKWh * exportRate / 12;
        const selfKWh_mo = yearlyEnergyKWh * (1 - exportRate) / 12;

        const monthly_charge = (selfKWh_mo * RETAIL) + (exportKWh_mo * FIT) - (inclLoan ? monthlyRepay : 0) - (billMonthly);

        // 25-year benefits
        const benefits25 = Array.from({ length: YRS_OF_SYSTEM }, (_, y) => {
            const pvYear = panels * DAY_POWER_AVG * DAYS_IN_YR * Math.pow(1 - SOLAR_PANEL_DEGRADATION, y);
            const self = pvYear * (1 - exportRate);
            const exp = pvYear * exportRate;
            const retailY = RETAIL * Math.pow(1 + ANNUAL_INCREASE, y);
            const fitY = FIT;
            return self * retailY + exp * fitY;
        }).reduce((a, b) => a + b, 0);

        const loanYearsCount = Math.min(YRS_OF_SYSTEM, LENGTH_OF_PAYBACK);
        const loanCost25 = inclLoan ? (monthlyRepay * 12 * loanYearsCount) : principal;
        const total_yr_savings = benefits25 - loanCost25 + (inclACA ? acaAllowance : 0);

        const payback_period = (() => {
            const investment = inclLoan ? loanCost25 : net_install_cost;
            const numerator = investment * (1 - CORPORATION_TAX);
            const annualEnergy = DAY_POWER_AVG * panels * DAYS_IN_YR;
            const valuePerKwh = (exportRate * FIT) + ((1 - exportRate) * RETAIL);
            const denom = annualEnergy * valuePerKwh;
            return denom > 0 ? numerator / denom : 0;
        })();

        const ROI_25Y = (() => {
            const cost = inclLoan ? (monthlyRepay * 12 * loanYearsCount) : principal;
            return cost > 0 ? ((benefits25 - cost) / cost) * 100 : 0;
        })();

        const savings_year0 = (() => {
            const k = panels * DAY_POWER_AVG * DAYS_IN_YR;
            const self = k * (1 - exportRate) * RETAIL;
            const exp = k * exportRate * FIT;
            const loan = inclLoan ? yearlyLoanCost : 0;
            const acaBump = (inclACA ? acaAllowance : 0);
            return self + exp - loan + acaBump;
        })();

        const co2_reduction = CO2_COEFFICIENT_TONNES *
            Array.from({ length: YRS_OF_SYSTEM }, (_, y) =>
                panels * DAY_POWER_AVG * Math.pow(1 - SOLAR_PANEL_DEGRADATION, y) * DAYS_IN_YR
            ).reduce((a, b) => a + b, 0);

        return {
            baseCost, seaiGrant, acaAllowance,
            install_cost, net_install_cost,
            yearlyEnergyKWh, monthly_charge, total_yr_savings,
            payback_period, ROI_25Y, savings_year0, co2_reduction
        };
    }

    // ===== GUI UPDATES =====
    function updateResults(state, figs) {
        const setTxt = (id, txt) => {
            document.querySelectorAll('#' + id).forEach(el => {
                if (!el || (el.tagName && el.tagName.toUpperCase() === 'INPUT')) return;
                el.textContent = txt;
            });
        };

        setTxt('installationCost', fmtEuro(figs.install_cost));
        setTxt('grant', fmtEuro(figs.seaiGrant));
        setTxt('panelCount', fmtNum(state.panels, 0));
        setTxt('yearlyEnergy', fmtNum(figs.yearlyEnergyKWh, 0) + ' kWh');
        setTxt('monthlyBill', fmtEuro(state.billMonthly));
        setTxt('annualIncrease', (ANNUAL_INCREASE * 100).toFixed(1));

        if (figs.monthly_charge < 0) {
            setTxt('netIncome', '-' + fmtEuro(Math.abs(figs.monthly_charge)));
            const el = byId('netIncome');
            if (el) el.style.color = 'red';
        } else {
            setTxt('netIncome', '+' + fmtEuro(figs.monthly_charge));
            const el = byId('netIncome');
            if (el) el.style.color = 'black';
        }

        setTxt('exportRate', (state.exportRate * 100).toFixed(0) + '%');
        setTxt('electricityRate', fmtEuro(state.electricityRate));
    }

    function updateInstallationDetails(state, figs) {
        const setTxt = (id, txt) => {
            document.querySelectorAll('#' + id).forEach(el => {
                if (!el || (el.tagName && el.tagName.toUpperCase() === 'INPUT')) return;
                el.textContent = txt;
            });
        };

        setTxt('netCost', fmtEuro(figs.net_install_cost));
        setTxt('totalSavings', fmtEuro(figs.total_yr_savings));
        setTxt('roi', fmtNum(figs.ROI_25Y, 1) + '%');
        setTxt('panelCount', fmtNum(state.panels, 0));
        setTxt('co2Reduction', fmtNum(figs.co2_reduction, 1) + ' t');
        setTxt('annualSavings', fmtEuro(figs.savings_year0));
        setTxt('paybackPeriod', fmtNum(figs.payback_period, 2) + ' years');
    }

    function updateSolarInvestmentAnalysis(state, figs) {
        const setTxt = (id, txt) => {
            document.querySelectorAll('#' + id).forEach(el => {
                if (!el || (el.tagName && el.tagName.toUpperCase() === 'INPUT')) return;
                el.textContent = txt;
            });
        };
        setTxt('panelCountValue', fmtNum(state.panels, 0));
        setTxt('installCost', fmtEuro(figs.baseCost));
    }

    // ===== MASTER CALCULATION =====
    function calculateROI() {
        const state = readInputs();

        if (!state.billMonthly) {
            const zeroFigs = keyFigures({ ...state, billMonthly: 0 });
            updateResults(state, zeroFigs);
            updateInstallationDetails(state, zeroFigs);
            updateSolarInvestmentAnalysis(state, zeroFigs);
            return;
        }

        const figs = keyFigures(state);
        updateResults(state, figs);
        updateInstallationDetails(state, figs);
        updateSolarInvestmentAnalysis(state, figs);

        // Trigger chart updates
        if (typeof updateEnergyChart === 'function') updateEnergyChart();
        if (typeof updateBreakEvenChart === 'function') updateBreakEvenChart(state, figs);
    }

    // ===== EVENT WIRING =====
    function wireEvents() {
        const onChangeRecalc = (id, ev = 'change') => {
            const el = byId(id);
            if (el) el.addEventListener(ev, calculateROI);
        };

        onChangeRecalc('inclGrant', 'change');
        onChangeRecalc('inclACA', 'change');
        onChangeRecalc('inclLoan', 'change');
        onChangeRecalc('panelCount', 'input');
        onChangeRecalc('exportRate', 'keyup');
        onChangeRecalc('electricityRate', 'keyup');
        onChangeRecalc('electricityBill', 'keyup');

        const btn = byId('openRoiModalButton');
        if (btn) btn.addEventListener('click', calculateROI);
    }

    // ===== INITIALIZATION =====
    function initialPopulate() {
        if (byId('panelCount') && !byId('panelCount').value) {
            byId('panelCount').value = DEFAULT_PANELS;
        }

        // Don't populate ROI values on page load - keep them at zero
        // const defaultState = readInputs();
        // const figs = keyFigures({ ...defaultState, billMonthly: defaultState.billMonthly || 0 });
        // updateResults(defaultState, figs);
        // updateInstallationDetails(defaultState, figs);
        // updateSolarInvestmentAnalysis(defaultState, figs);
    }

    document.addEventListener('DOMContentLoaded', () => {
        wireEvents();
        initialPopulate();
    });

    // Expose for external access
    window.calculateROI = calculateROI;
    window.readInputs = readInputs;
    window.keyFigures = keyFigures;

})();
