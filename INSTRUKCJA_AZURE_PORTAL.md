# INSTRUKCJA WDROŻENIA PRZEZ AZURE PORTAL GUI

## Przed rozpoczęciem
1. Upewnij się, że masz dostęp do Azure Portal
2. Przygotuj listę sekretów do GitHub Actions:
   - `AZURE_CLIENT_ID`
   - `AZURE_TENANT_ID`
   - `AZURE_SUBSCRIPTION_ID`
   - `REGISTRY_USERNAME`
   - `REGISTRY_PASSWORD`
3. Sprawdź, czy masz odpowiednie uprawnienia w Azure (co najmniej Contributor)
4. Przygotuj listę plików konfiguracyjnych do przeniesienia

---

## 1. Utwórz Resource Group
1. Wejdź w Azure Portal
2. Kliknij "Create a resource"
3. Wyszukaj "Resource Group"
4. Kliknij "Create"
5. Wypełnij:
   - **Subscription:** Twój subskrypcja
   - **Resource Group name:** `smsapi-prod2`
   - **Region:** `West Europe`
6. Kliknij "Review + create" → "Create"
7. **Weryfikacja:** Upewnij się, że Resource Group została utworzona w regionie West Europe

---

## 2. Utwórz Azure Container Registry (ACR)
1. W nowej Resource Group kliknij "Create"
2. Wyszukaj "Container Registry"
3. Kliknij "Create"
4. Wypełnij:
   - **Registry name:** `smsapiregistry2`
   - **Location:** `West Europe`
   - **SKU:** `Basic`
5. Kliknij "Review + create" → "Create"
6. **Weryfikacja:** Sprawdź, czy ACR jest dostępny i działa

---

## 3. Utwórz Storage Account
1. W Resource Group kliknij "Create"
2. Wyszukaj "Storage Account"
3. Kliknij "Create"
4. Wypełnij:
   - **Storage account name:** `smsapistorage2024b`
   - **Location:** `West Europe`
   - **Performance:** `Standard`
   - **Redundancy:** `Locally-redundant storage (LRS)`
5. Kliknij "Review + create" → "Create"
6. **Weryfikacja:** Sprawdź, czy Storage Account jest dostępny

---

## 4. Utwórz File Shares
1. Wejdź w utworzony Storage Account
2. W menu po lewej kliknij "File shares"
3. Kliknij "+ File share"
4. Utwórz pierwszy share:
   - **Name:** `smsapiconfig2`
   - **Quota:** `5` GB
5. Kliknij "Create"
6. Powtórz dla drugiego share:
   - **Name:** `smsapilog2`
   - **Quota:** `5` GB
7. **Weryfikacja:** Sprawdź, czy oba File Shares są dostępne


## 7. Utwórz Container App
1. W Resource Group kliknij "Create"
2. Wyszukaj "Container App"
3. Kliknij "Create"
4. Wypełnij:
   - **Name:** `jbitrixwapp3`
   - **Container Apps Environment:**
     - Jeśli nie masz jeszcze środowiska, kliknij "Create new" – środowisko utworzy się automatycznie.
     - Jeśli już masz środowisko (`smsapi-env2`), wybierz je z listy.
   - **Region:** `West Europe`
5. W sekcji "Container":
   - **Container Image Source:**
     - Jeśli nie masz jeszcze własnego obrazu w ACR, wybierz **Docker Hub** i wpisz:
       - **Image:** `nginx`
       - **Tag:** `latest`
     - Jeśli masz już własny obraz w ACR, wybierz **Azure Container Registry** i podaj:
       - **Registry:** `smsapiregistry2.azurecr.io`
       - **Image:** `smsapi-app`
       - **Tag:** `latest`
   - **CPU:** `0.5`
   - **Memory:** `1.0 Gi`
6. W sekcji "Ingress":
   - **Ingress:** Enabled
   - **Ingress traffic:** Accepting traffic from anywhere
   - **Ingress type:** HTTP
   - **Target port:** 80
7. Kliknij "Review + create" → "Create"
8. **Weryfikacja:** Sprawdź, czy Container App jest utworzona

**Uwaga:**
- Jeśli tworzysz Container App po raz pierwszy, środowisko (Container Apps Environment) utworzy się automatycznie.
- Jeśli nie masz jeszcze własnego obrazu w ACR, użyj publicznego obrazu (np. nginx:latest). Po pierwszym deployu z GitHub Actions obraz zostanie automatycznie nadpisany na Twój.

## 5. Utwórz Container Apps Environment
1. W Resource Group kliknij "Create"
2. Wyszukaj "Container Apps Environment"
3. Kliknij "Create"
4. Wypełnij:
   - **Name:** `smsapi-env2`
   - **Location:** `West Europe`
5. Kliknij "Review + create" → "Create"
6. **Weryfikacja:** Sprawdź, czy Environment jest gotowe

---

## 6. Dodaj File Shares do Environment
1. Wejdź w utworzone Container Apps Environment
2. W menu po lewej kliknij "Azure Files"
3. Kliknij "Add"
4. Dla pierwszego share:
   - **Storage type:** `SMB`
   - **Storage account:** `smsapistorage2024b`
   - **File share:** `smsapiconfig2`
   - **Access key:** (skopiuj z Storage Account → Access keys)
5. Kliknij "Add"
6. Powtórz dla drugiego share:
   - **File share:** `smsapilog2`
7. **Weryfikacja:** Sprawdź, czy oba File Shares są poprawnie podpięte

---



---

## 8. Podepnij Volumes do Container App
1. Wejdź w utworzoną Container App
2. W menu po lewej kliknij "Volumes"
3. Kliknij "Add"
4. Dla pierwszego volume:
   - **Volume type:** `Azure file volume`
   - **Name:** `smsapiconfig2`
   - **File share name:** `smsapiconfig2`
   - **Mount path:** `/var/www/html/config`
5. Kliknij "Add"
6. Powtórz dla drugiego volume:
   - **Name:** `smsapilog2`
   - **File share name:** `smsapilog2`
   - **Mount path:** `/var/www/html/var/log`
7. **Weryfikacja:** Sprawdź, czy oba Volumes są poprawnie zamontowane

---

## 9. Skonfiguruj GitHub Actions
1. W GitHubie, w swoim repozytorium:
   - Przejdź do Settings → Secrets and variables → Actions
   - Dodaj sekrety:
     - `AZURE_CLIENT_ID`
     - `AZURE_TENANT_ID`
     - `AZURE_SUBSCRIPTION_ID`
     - `REGISTRY_USERNAME`
     - `REGISTRY_PASSWORD`
2. W Azure Portal:
   - Wejdź w Container App
   - W menu po lewej kliknij "GitHub Actions"
   - Kliknij "Configure GitHub Actions"
   - Wybierz swoje repozytorium
   - Wybierz branch `main`
   - Kliknij "Create"
3. **Weryfikacja:** Sprawdź, czy workflow został utworzony w GitHubie

---

## 10. Przenieś dane
1. Skopiuj pliki konfiguracyjne do File Share `smsapiconfig2`:
   - Użyj Azure Portal → Storage Account → File Shares
   - Lub użyj Azure Storage Explorer
2. Upewnij się, że uprawnienia są poprawne
3. **Weryfikacja:** Sprawdź, czy pliki są dostępne w File Share

---

## 11. Weryfikacja deploymentu
1. Sprawdź logi Container App:
   - Wejdź w Container App → Logs
   - Upewnij się, że nie ma błędów
2. Zweryfikuj, czy Volumes są poprawnie zamontowane:
   - Sprawdź, czy aplikacja widzi pliki w `/var/www/html/config`
   - Sprawdź, czy logi są zapisywane w `/var/www/html/var/log`
3. Przetestuj endpointy aplikacji:
   - Otwórz URL aplikacji
   - Sprawdź, czy webhooki działają

## 12. Weryfikacja uprawnień i dostępności
1. Sprawdź dostęp do ACR:
   - Wejdź w ACR → Access control (IAM)
   - Upewnij się, że Container App ma rolę "AcrPull"
   - Sprawdź, czy GitHub Actions ma uprawnienia do push

2. Sprawdź dostęp do File Shares:
   - Wejdź w Storage Account → Access control (IAM)
   - Upewnij się, że Container App ma rolę "Storage File Data SMB Share Contributor"
   - Sprawdź uprawnienia na poziomie File Share:
     - Kliknij File Share → Access Control
     - Upewnij się, że Container App ma uprawnienia do odczytu i zapisu

3. Sprawdź dostęp do Environment:
   - Wejdź w Environment → Access control (IAM)
   - Upewnij się, że Container App ma rolę "Contributor"
   - Sprawdź, czy GitHub Actions ma uprawnienia do deploymentu

4. Test zapisu do File Shares:
   - Wejdź w Container App → Console
   - Wykonaj komendy:
     ```bash
     # Test zapisu do config
     echo "test" > /var/www/html/config/test.txt
     cat /var/www/html/config/test.txt
     
     # Test zapisu do logów
     echo "test log" > /var/www/html/var/log/test.log
     cat /var/www/html/var/log/test.log
     ```
   - Sprawdź w Azure Portal, czy pliki pojawiły się w File Shares

5. Sprawdź połączenie z ACR:
   - Wejdź w Container App → Console
   - Wykonaj:
     ```bash
     # Sprawdź, czy można się zalogować do ACR
     az acr login --name smsapiregistry2
     ```

---

## Po wdrożeniu
1. Skonfiguruj monitoring i alerty:
   - Dodaj alerty na CPU, pamięć, błędy
   - Skonfiguruj powiadomienia
2. Ustaw backup File Shares:
   - Skonfiguruj Azure Backup
   - Ustaw harmonogram backupów
3. Przetestuj proces disaster recovery:
   - Przetestuj przywracanie z backupu
   - Sprawdź procedury awaryjne

---

## Czyszczenie (jeśli coś pójdzie nie tak)
1. Usuń Container App:
   - Wejdź w Container App → Delete
2. Usuń Environment:
   - Wejdź w Environment → Delete
3. Usuń File Shares:
   - Wejdź w Storage Account → File Shares → Delete
4. Usuń Storage Account:
   - Wejdź w Storage Account → Delete
5. Usuń Container Registry:
   - Wejdź w ACR → Delete
6. Usuń Resource Group:
   - Wejdź w Resource Group → Delete

---

## Ważne uwagi:
1. Wszystkie zasoby są tworzone w regionie **West Europe**
2. Używamy nowych, unikalnych nazw zasobów (z sufiksem 2 lub 3)
3. File Shares są konfigurowane jako **SMB** (nie NFS)
4. Volumes są montowane w odpowiednich ścieżkach:
   - `/var/www/html/config` dla konfiguracji
   - `/var/www/html/var/log` dla logów
5. Szacowane koszty miesięczne:
   - Container Registry (Basic): ~$5
   - Storage Account: ~$20
   - Container App: ~$30-50
   - Razem: ~$55-75/miesiąc

---

## Rozwiązywanie problemów:
1. Jeśli GitHub Actions nie uruchamia się automatycznie:
   - Sprawdź sekrety w GitHubie
   - Sprawdź uprawnienia w Azure
2. Jeśli Container App nie startuje:
   - Sprawdź logi w Azure Portal
   - Upewnij się, że obrazy są poprawnie wypychane do ACR
3. Jeśli Volumes nie działają:
   - Sprawdź uprawnienia do Storage Account
   - Upewnij się, że File Shares są poprawnie skonfigurowane w Environment
4. Jeśli aplikacja nie działa:
   - Sprawdź logi aplikacji
   - Zweryfikuj konfigurację
   - Sprawdź połączenia z zewnętrznymi serwisami
5. Jeśli występują problemy z uprawnieniami:
   - Sprawdź role w IAM dla każdego komponentu
   - Upewnij się, że wszystkie komponenty są w tym samym regionie
   - Sprawdź, czy File Shares są poprawnie skonfigurowane jako SMB
   - Zweryfikuj, czy Container App ma odpowiednie role do odczytu/zapisu 

## Po pierwszym deployu z GitHub Actions
- Po wypchnięciu własnego obrazu do ACR przez workflow, Container App automatycznie przełączy się na Twój obraz (`smsapiregistry2.azurecr.io/smsapi-app:latest`).
- Nie musisz ręcznie zmieniać obrazu w portalu – workflow zrobi to za Ciebie. 