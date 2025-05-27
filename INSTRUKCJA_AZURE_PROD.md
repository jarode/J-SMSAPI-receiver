# INSTRUKCJA WDROŻENIA PRODUKCYJNEGO – AZURE CONTAINER APPS (WEST EUROPE)

**Cel:** Utworzenie kompletnego środowiska produkcyjnego dla integracji SMSAPI ↔ Bitrix24 w Azure, z trwałym storage (Azure File Share), w jednym regionie (West Europe), na nowych zasobach.

---

## 1. Utwórz Resource Group

```bash
az group create --name smsapi-prod2 --location westeurope
```

---

## 2. Utwórz Azure Container Registry (ACR)

```bash
az acr create --resource-group smsapi-prod2 --name smsapiregistry2 --sku Basic --location westeurope
```

---

## 3. Utwórz Storage Account (West Europe)

```bash
az storage account create --name smsapistorage2024b --resource-group smsapi-prod2 --location westeurope --sku Standard_LRS
```

---

## 4. Utwórz File Share na Storage Account

```bash
az storage share-rm create --resource-group smsapi-prod2 --storage-account smsapistorage2024b --name smsapiconfig2 --quota 5
az storage share-rm create --resource-group smsapi-prod2 --storage-account smsapistorage2024b --name smsapilog2 --quota 5
```

---

## 5. Pobierz klucz dostępu do Storage Account

```bash
az storage account keys list --account-name smsapistorage2024b --resource-group smsapi-prod2 -o table
```
Zanotuj wartość `key1`.

---

## 6. Utwórz Container Apps Environment (West Europe)

```bash
az containerapp env create --name smsapi-env2 --resource-group smsapi-prod2 --location westeurope
```

---

## 7. Dodaj File Share do środowiska Container Apps Environment

1. Wejdź w Azure Portal → Resource Group → smsapi-env2 (Container Apps Environment)
2. W menu po lewej wybierz **Azure Files** → **Add**
3. Wybierz typ: **SMB**
4. Podaj:
   - Storage Account: smsapistorage2024b
   - File Share: smsapiconfig2 (potem powtórz dla smsapilog2)
   - Access Key: (wartość key1)
5. Zapisz

---

## 8. Utwórz Container App (West Europe)

Możesz przez Portal lub CLI. Przykład CLI:
```bash
az containerapp create \
  --name jbitrixwapp3 \
  --resource-group smsapi-prod2 \
  --environment smsapi-env2 \
  --image smsapiregistry2.azurecr.io/smsapi-app:latest \
  --target-port 80 \
  --ingress external \
  --registry-server smsapiregistry2.azurecr.io \
  --cpu 0.5 --memory 1.0Gi
```

---

## 9. Podepnij File Share jako volume do Container App

1. Wejdź w Container App → **Volumes** → **Add**
2. Wybierz:
   - Volume type: Azure file volume
   - Name: smsapiconfig2
   - File share name: smsapiconfig2
   - Mount path: `/var/www/html/config`
3. Dodaj drugi volume:
   - Name: smsapilog2
   - File share name: smsapilog2
   - Mount path: `/var/www/html/var/log`
4. Zapisz i poczekaj na restart aplikacji.

---

## 10. Dodaj sekrety i zmienne środowiskowe (jeśli potrzebujesz)
- W Container App → **Secrets** możesz dodać np. hasła do API, tokeny itp.
- W Container App → **Configuration** → **Environment variables** możesz dodać zmienne środowiskowe.

---

## 11. Skonfiguruj workflow GitHub Actions (deploy do ACR i Container App)
- Upewnij się, że workflow używa poprawnych nazw zasobów i regionu.
- Przykład fragmentu:
```yaml
      - name: Build and push container image to registry
        uses: azure/container-apps-deploy-action@v2
        with:
          appSourcePath: ${{ github.workspace }}
          dockerfilePath: ./Dockerfile
          registryUrl: smsapiregistry2.azurecr.io
          imageToBuild: smsapiregistry2.azurecr.io/smsapi-app:${{ github.sha }}
          containerAppName: jbitrixwapp3
          resourceGroup: smsapi-prod2
          containerAppEnvironment: smsapi-env2
```

---

## 12. (Opcjonalnie) Skonfiguruj Log Analytics
- Utwórz Log Analytics Workspace w West Europe.
- Podepnij do Container App Environment.

---

## 13. Przenieś konfigurację, sekrety, webhooki
- Skopiuj pliki konfiguracyjne do File Share (np. przez Azure Portal lub azcopy).
- Zaktualizuj webhooki Bitrix24 na nowy endpoint.
- Przetestuj działanie aplikacji.

---

## 14. Usuń stare zasoby w innych regionach
- Po migracji usuń stare Container Apps, środowiska i Storage Account w West US 2.

---

**Wszystko jest teraz w jednym regionie (West Europe), storage jest trwały, a deploye nie kasują plików!**

---

**TIP:**
- Zawsze trzymaj wszystkie zasoby produkcyjne w jednym regionie.
- Używaj File Share tylko do plików konfiguracyjnych i logów, nie do bazy danych.
- Regularnie sprawdzaj uprawnienia i koszty zasobów. 